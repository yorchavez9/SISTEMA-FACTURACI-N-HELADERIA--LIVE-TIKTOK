<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\ElectronicDocumentType;
use App\Enums\IdentityDocType;
use App\Integrations\Facturacion\ElectronicDocumentService;
use App\Integrations\Facturacion\Exceptions\FacturacionException;
use App\Models\Customer;
use App\Models\ElectronicDocument;
use App\Models\Sale;
use App\Services\SaleService;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ElectronicDocumentController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'type' => ['nullable', Rule::enum(ElectronicDocumentType::class)],
            'status' => ['nullable', Rule::enum(DocumentStatus::class)],
            'search' => ['nullable', 'string', 'max:30'],
        ]);
        $filters['from'] ??= today()->subDays(6)->toDateString();
        $filters['to'] ??= today()->toDateString();

        $documents = ElectronicDocument::query()
            ->with(['sale:id,total,change,payment_method,status', 'latestVoiding', 'related:id,type,series,number'])
            ->whereDate('issued_at', '>=', $filters['from'])
            ->whereDate('issued_at', '<=', $filters['to'])
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['search'] ?? null, function ($q, $s) {
                if (preg_match('/^([A-Z0-9]{4})-?0*(\d+)$/i', $s, $m)) {
                    $q->where('series', strtoupper($m[1]))->where('number', (int) $m[2]);
                } else {
                    $q->where('customer_snapshot->num_doc', 'like', "{$s}%");
                }
            })
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(function (ElectronicDocument $doc) {
                // Acciones del menú: true o el motivo por el que no está disponible.
                $doc->setAttribute('actions', $doc->availableActions());
                $doc->setAttribute('voiding', $doc->latestVoiding ? [
                    'kind' => $doc->latestVoiding->kind,
                    'label' => $doc->latestVoiding->kindLabel(),
                    'identifier' => $doc->latestVoiding->identifier,
                    'status' => $doc->latestVoiding->status->value,
                    'message' => $doc->latestVoiding->sunat_message,
                ] : null);
                $doc->unsetRelation('latestVoiding');

                return $doc;
            });

        return Inertia::render('documents/index', [
            'documents' => $documents,
            'filters' => $filters,
            'types' => ElectronicDocumentType::options(),
            'statuses' => DocumentStatus::options(),
            'pdfFormat' => Settings::get('pdf_format', 'ticket-80'),
        ]);
    }

    public function show(ElectronicDocument $document): Response
    {
        $document->load(['sale.items', 'related:id,type,series,number,status', 'creditNote:id,related_document_id,type,series,number,status']);

        return Inertia::render('documents/show', [
            'document' => $document->makeVisible('response_payload'),
            'canCorrect' => $document->status->isCorrectable() || in_array($document->status, [DocumentStatus::Error, DocumentStatus::RevisionManual], true),
            'docTypes' => IdentityDocType::options(),
        ]);
    }

    /** Emite boleta/factura para una venta registrada sin comprobante. */
    public function store(Request $request, Sale $sale, SaleService $sales, ElectronicDocumentService $service): RedirectResponse
    {
        $data = $request->validate([
            'document_type' => ['required', Rule::in([DocumentType::Boleta->value, DocumentType::Factura->value])],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
        ]);

        abort_if($sale->isVoided() || $sale->invoice !== null || ! Settings::billingConfigured(), 422, 'No se puede emitir comprobante para esta venta.');

        $type = DocumentType::from($data['document_type']);
        $customer = isset($data['customer_id']) ? Customer::find($data['customer_id']) : $sale->customer;
        $sales->ensureDocumentRules($type, $customer, (float) $sale->total);

        $sale->update(['customer_id' => $customer?->id, 'document_type' => $type]);
        $document = $service->issueForSale($sale->fresh(), $type);

        return to_route('documents.show', $document)->with(
            $document->status->neverValid() ? 'error' : 'success',
            "{$document->type->label()} {$document->full_number}: {$document->status->label()}"
        );
    }

    public function refresh(ElectronicDocument $document, ElectronicDocumentService $service): RedirectResponse
    {
        $service->refresh($document);

        return back()->with('success', "Estado actualizado: {$document->status->label()}.");
    }

    public function resend(ElectronicDocument $document, ElectronicDocumentService $service): RedirectResponse
    {
        abort_if($document->status === DocumentStatus::Aceptado || $document->status === DocumentStatus::Anulado, 422, 'El comprobante no requiere reenvío.');

        $service->resend($document);

        return back()->with($document->status->neverValid() ? 'error' : 'success', "Reenvío: {$document->status->label()}.");
    }

    /** Corrige el cliente de un comprobante rechazado/con error y lo reenvía. */
    public function update(Request $request, ElectronicDocument $document, ElectronicDocumentService $service): RedirectResponse
    {
        abort_unless($document->status->neverValid() || $document->status === DocumentStatus::Pendiente, 422, 'El comprobante no puede corregirse.');
        abort_if($document->status === DocumentStatus::Anulado, 422);

        $isFactura = $document->type === ElectronicDocumentType::Factura;
        $data = $request->validate([
            'tipo_doc' => ['required', Rule::in($isFactura ? [IdentityDocType::Ruc->value] : ['0', '1', '4', '6', '7'])],
            'num_doc' => ['required', 'string', 'max:15', $isFactura ? 'digits:11' : 'alpha_num'],
            'razon_social' => ['required', 'string', 'max:200'],
            'direccion' => [Rule::requiredIf($isFactura), 'nullable', 'string', 'max:255'],
        ]);

        $service->correctCustomer($document, $data);

        return back()->with($document->status->neverValid() ? 'error' : 'success', "Comprobante corregido: {$document->status->label()}.");
    }

    public function download(Request $request, ElectronicDocument $document, string $kind, ElectronicDocumentService $service): HttpResponse
    {
        abort_unless(in_array($kind, ['pdf', 'xml', 'cdr'], true), 404);
        $format = $request->query('format');
        abort_if($format && ! in_array($format, ['a4', 'a5', 'ticket-80', 'ticket-58'], true), 422);

        try {
            $file = $service->download($document, $kind, $format);
        } catch (FacturacionException $e) {
            // Se muestra dentro del visor (iframe) o la pestaña: no redirigir.
            return response(view('document-error', ['message' => $e->getMessage()]), 502);
        }

        $inline = $kind === 'pdf' && ! $request->boolean('download');
        $filename = $format && $kind === 'pdf'
            ? str_replace('.pdf', "-{$format}.pdf", $file->filename)
            : $file->filename;

        return response($file->content, 200, [
            'Content-Type' => $file->mimeType,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$filename.'"',
            'Cache-Control' => 'private, max-age=60',
        ]);
    }

    /**
     * Estado del comprobante en JSON (para el visor del POS, que lo consulta cada pocos segundos).
     * Si aún espera respuesta de SUNAT, consulta al proveedor (como máximo cada 3 segundos).
     */
    public function status(ElectronicDocument $document, ElectronicDocumentService $service): JsonResponse
    {
        try {
            if (in_array($document->status, DocumentStatus::awaitingSunat(), true)
                && (! $document->last_checked_at || $document->last_checked_at->lte(now()->subSeconds(3)))) {
                $service->refresh($document);
            } elseif ($document->status === DocumentStatus::ErrorEnvio
                && (! $document->last_attempt_at || $document->last_attempt_at->lte(now()->subSeconds(15)))) {
                $service->send($document); // reintento espaciado (respeta max_attempts)
            }
        } catch (FacturacionException) {
            // se informa el último estado conocido
        }
        $document->refresh();

        return response()->json([
            'id' => $document->id,
            'number' => $document->full_number,
            'status' => $document->status->value,
            'status_label' => $document->status->label(),
            'message' => $document->sunat_message,
            'sunat_code' => $document->sunat_code,
            'registered' => $document->isRegisteredInProvider(),
            'final' => ! in_array($document->status, [...DocumentStatus::awaitingSunat(), DocumentStatus::ErrorEnvio, DocumentStatus::Borrador], true),
        ]);
    }

    /** Verificación directa en SUNAT (consultar-cpe). */
    public function consult(ElectronicDocument $document, ElectronicDocumentService $service): RedirectResponse
    {
        abort_if($document->number === null, 422, 'El comprobante aún no tiene número.');

        $result = $service->consultSunat($document);
        $message = $result['data']['estado'] ?? $result['estado'] ?? $result['message'] ?? $result['mensaje'] ?? json_encode($result, JSON_UNESCAPED_UNICODE);

        return back()->with('success', 'SUNAT: '.(is_string($message) ? $message : json_encode($message, JSON_UNESCAPED_UNICODE)));
    }
}
