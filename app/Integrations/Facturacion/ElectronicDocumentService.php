<?php

namespace App\Integrations\Facturacion;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\ElectronicDocumentType;
use App\Integrations\Facturacion\Contracts\InvoicingProvider;
use App\Integrations\Facturacion\DTO\DownloadedFile;
use App\Integrations\Facturacion\DTO\ProviderDocument;
use App\Integrations\Facturacion\DTO\ProviderVoiding;
use App\Integrations\Facturacion\Exceptions\FacturacionException;
use App\Integrations\Facturacion\Exceptions\ProviderUnavailableException;
use App\Models\DocumentVoiding;
use App\Models\ElectronicDocument;
use App\Models\Sale;
use App\Support\Settings;
use Illuminate\Support\Facades\Log;

/**
 * Orquesta la emisión de comprobantes: guarda el registro local, llama al
 * proveedor y persiste la respuesta. Nunca lanza excepción por fallas del
 * proveedor al emitir: deja el documento en un estado recuperable.
 */
class ElectronicDocumentService
{
    public function __construct(
        private InvoicingProvider $provider,
        private PayloadBuilder $builder,
    ) {}

    /** Crea el comprobante de una venta y lo envía al proveedor. */
    public function issueForSale(Sale $sale, DocumentType $documentType): ElectronicDocument
    {
        $type = $documentType->electronicType()
            ?? throw new \InvalidArgumentException('La venta no requiere comprobante.');

        $sale->loadMissing('items', 'customer');
        $customer = $sale->customer?->toSnapshot() ?? PayloadBuilder::CONSUMIDOR_FINAL;
        $series = $type === ElectronicDocumentType::Factura
            ? Settings::get('series_factura')
            : Settings::get('series_boleta');

        $document = ElectronicDocument::create([
            'sale_id' => $sale->id,
            'type' => $type,
            'series' => $series,
            'customer_snapshot' => $customer,
            'total' => $sale->total,
            'status' => DocumentStatus::Borrador,
            'request_payload' => $this->builder->forSale($sale, $type, $series, $customer),
            'issued_at' => $sale->sold_at,
        ]);

        $this->send($document);

        return $document->refresh();
    }

    /** Envía (o reintenta enviar) un comprobante que aún no está registrado en el proveedor. */
    public function send(ElectronicDocument $document): void
    {
        if ($document->isRegisteredInProvider()) {
            $this->refresh($document);

            return;
        }

        $document->attempts++;
        $document->last_attempt_at = now();

        try {
            // Reintento: si un intento anterior sí llegó al proveedor, se vincula en vez de emitir otro.
            if ($document->attempts > 1 && ($existing = $this->findExisting($document))) {
                $this->apply($document, $existing);

                return;
            }

            $result = $this->provider->issue($document->type, $document->request_payload);
            $this->apply($document, $result);
        } catch (ProviderUnavailableException $e) {
            $document->status = $document->attempts >= config('facturacion.max_attempts')
                ? DocumentStatus::RevisionManual
                : DocumentStatus::ErrorEnvio;
            $document->sunat_message = $e->getMessage();
            $document->save();
        } catch (FacturacionException $e) {
            $document->status = DocumentStatus::Error;
            $document->sunat_message = $e->getMessage();
            $document->response_payload = $e->context ?: null;
            $document->save();
        }
    }

    /** Consulta el estado actual en el proveedor. */
    public function refresh(ElectronicDocument $document): void
    {
        if (! $document->isRegisteredInProvider()) {
            if (in_array($document->status, [DocumentStatus::ErrorEnvio, DocumentStatus::Borrador], true)) {
                $this->send($document);
            }

            return;
        }

        $document->last_checked_at = now();

        try {
            $this->apply($document, $this->provider->find($document->type, $document->provider_id));
        } catch (FacturacionException $e) {
            $document->save();
            throw $e;
        }
    }

    /** Pide al proveedor reenviar a SUNAT. */
    public function resend(ElectronicDocument $document): void
    {
        if (! $document->isRegisteredInProvider()) {
            $this->send($document);

            return;
        }

        $this->apply($document, $this->provider->resend($document->type, $document->provider_id));
    }

    /** Corrige los datos del cliente de un comprobante rechazado o con error. */
    public function correctCustomer(ElectronicDocument $document, array $customer): void
    {
        $payload = $this->builder->withCustomer($document->request_payload, $customer);
        $document->customer_snapshot = $customer;
        $document->request_payload = $payload;

        if (! $document->isRegisteredInProvider()) {
            // Nunca llegó al proveedor: se emite de nuevo con los datos corregidos.
            $document->status = DocumentStatus::Borrador;
            $document->save();
            $this->send($document);

            return;
        }

        $this->apply($document, $this->provider->update($document->type, $document->provider_id, $payload));
    }

    /**
     * Anula un comprobante aceptado emitiendo una nota de crédito (motivo 01).
     * Si el proveedor no registra la NC, lanza excepción y no deja rastro local.
     */
    public function voidWithCreditNote(ElectronicDocument $original, string $reason): ElectronicDocument
    {
        $original->loadMissing('sale.items');

        $creditNote = $this->issueNote(
            $original,
            ElectronicDocumentType::NotaCredito,
            '01',
            $reason ?: 'Anulación de la operación',
            $this->builder->saleItems($original->sale),
        );

        $original->update(['status' => DocumentStatus::Anulado]);

        return $creditNote;
    }

    /**
     * Emite una nota de crédito o débito sobre un comprobante aceptado.
     * Si el proveedor no la registra, lanza excepción y no deja rastro local.
     *
     * @param  array<int, array<string, mixed>>  $items  ítems en formato del API (precio con IGV)
     */
    public function issueNote(ElectronicDocument $original, ElectronicDocumentType $type, string $code, string $reason, array $items): ElectronicDocument
    {
        if ($original->status !== DocumentStatus::Aceptado || ! $original->isBase()) {
            throw new FacturacionException('Solo se pueden emitir notas sobre una boleta o factura aceptada por SUNAT.');
        }
        if ($items === []) {
            throw new FacturacionException('La nota no tiene ítems.');
        }

        $isFactura = $original->type === ElectronicDocumentType::Factura;
        $series = match ($type) {
            ElectronicDocumentType::NotaCredito => Settings::get($isFactura ? 'series_nc_factura' : 'series_nc_boleta'),
            ElectronicDocumentType::NotaDebito => Settings::get($isFactura ? 'series_nd_factura' : 'series_nd_boleta'),
            default => throw new \InvalidArgumentException('Tipo de nota inválido.'),
        };

        $total = round(array_sum(array_map(fn ($i) => (float) $i['cantidad'] * (float) $i['precio_unitario'], $items)), 2);

        $note = new ElectronicDocument([
            'sale_id' => $original->sale_id,
            'type' => $type,
            'series' => $series,
            'related_document_id' => $original->id,
            'reason_code' => $code,
            'reason' => mb_substr($reason, 0, 255),
            'customer_snapshot' => $original->customer_snapshot,
            'total' => $total,
            'status' => DocumentStatus::Borrador,
            'request_payload' => $this->builder->note($original, $series, $code, $reason, $items),
            'issued_at' => now(),
            'attempts' => 1,
            'last_attempt_at' => now(),
        ]);

        $this->apply($note, $this->provider->issue($type, $note->request_payload));

        return $note;
    }

    /** Total ya acreditado con notas de crédito válidas (para no exceder el comprobante). */
    public function creditedTotal(ElectronicDocument $original): float
    {
        return (float) $original->notes()
            ->where('type', ElectronicDocumentType::NotaCredito->value)
            ->whereNotIn('status', [DocumentStatus::Rechazado->value, DocumentStatus::Error->value, DocumentStatus::Anulado->value])
            ->sum('total');
    }

    /**
     * Comunicación de baja (RA) o resumen de anulación (RC) de un comprobante aceptado.
     * El comprobante pasa a "anulado" cuando SUNAT acepta la baja.
     */
    public function requestVoiding(ElectronicDocument $document, string $reason, ?int $userId = null): DocumentVoiding
    {
        $allowed = $document->availableActions()['void'];
        if ($allowed !== true) {
            throw new FacturacionException($allowed);
        }

        $kind = $document->voidingKind();
        $payload = $kind === 'RC'
            ? $this->builder->voidSummary($document, $reason)
            : $this->builder->voidCommunication($document, $reason);

        // Lanza excepción si el proveedor no la registra: no se guarda nada.
        $result = $this->provider->voidDocument($kind, $payload);

        $voiding = new DocumentVoiding([
            'electronic_document_id' => $document->id,
            'user_id' => $userId,
            'kind' => $kind,
            'reason' => mb_substr($reason, 0, 255),
            'request_payload' => $payload,
        ]);
        $this->applyVoiding($voiding, $result);

        return $voiding;
    }

    public function refreshVoiding(DocumentVoiding $voiding): void
    {
        $voiding->last_checked_at = now();

        if (! $voiding->provider_id) {
            $voiding->save();

            return;
        }

        $this->applyVoiding($voiding, $this->provider->voidingStatus($voiding->kind, $voiding->provider_id));
    }

    private function applyVoiding(DocumentVoiding $voiding, ProviderVoiding $result): void
    {
        $voiding->provider_id ??= $result->providerId;
        $voiding->identifier = $result->identifier ?? $voiding->identifier;
        $voiding->ticket = $result->ticket ?? $voiding->ticket;
        $voiding->status = $result->status ?? $voiding->status ?? DocumentStatus::Pendiente;
        $voiding->sunat_code = $result->sunatCode ?? $voiding->sunat_code;
        $voiding->sunat_message = $result->sunatMessage ?? $voiding->sunat_message;
        $voiding->response_payload = $result->raw;
        $voiding->save();

        // Baja aceptada (en RC la boleta figura "anulado"): el comprobante queda anulado.
        if (in_array($voiding->status, [DocumentStatus::Aceptado, DocumentStatus::Anulado], true)) {
            $voiding->status = DocumentStatus::Aceptado;
            $voiding->save();
            $voiding->document()->update(['status' => DocumentStatus::Anulado->value]);
        }
    }

    /**
     * Descarta un comprobante que nunca fue válido (al anular la venta).
     * Si el proveedor tiene una boleta no aceptada, intenta eliminarla.
     */
    public function discard(ElectronicDocument $document): void
    {
        if ($document->isRegisteredInProvider() && $document->type === ElectronicDocumentType::Boleta) {
            try {
                $this->provider->delete($document->type, $document->provider_id);
            } catch (FacturacionException $e) {
                Log::channel('facturacion')->info("No se pudo eliminar la boleta {$document->full_number} en el proveedor", ['error' => $e->getMessage()]);
            }
        }

        $document->update(['status' => DocumentStatus::Anulado]);
    }

    public function download(ElectronicDocument $document, string $kind, ?string $format = null): DownloadedFile
    {
        if (! $document->isRegisteredInProvider()) {
            throw new FacturacionException('El comprobante aún no está registrado en el servicio de facturación.');
        }

        $file = $this->provider->download($document->type, $document->provider_id, $kind, $format ?? Settings::get('pdf_format', 'ticket-80'));

        return new DownloadedFile($file->content, $file->mimeType, $document->full_number.'.'.pathinfo($file->filename, PATHINFO_EXTENSION));
    }

    /** Verificación directa en SUNAT (consultar-cpe). */
    public function consultSunat(ElectronicDocument $document): array
    {
        return $this->provider->consultCpe([
            'ruc_emisor' => Settings::get('company_ruc'),
            'tipo_doc' => $document->type->value,
            'serie' => $document->series,
            'correlativo' => $document->number,
            'fecha_emision' => $document->issued_at->toDateString(),
            'monto_total' => number_format((float) $document->total, 2, '.', ''),
        ]);
    }

    /** Busca en el proveedor un comprobante ya creado para este documento local. */
    private function findExisting(ElectronicDocument $document): ?ProviderDocument
    {
        $linked = ElectronicDocument::query()
            ->where('type', $document->type)
            ->where('series', $document->series)
            ->whereNotNull('provider_id')
            ->pluck('provider_id')
            ->all();

        try {
            return $this->provider->findMatching($document->type, $document->request_payload, (float) $document->total, $linked);
        } catch (ProviderUnavailableException $e) {
            throw $e;
        } catch (FacturacionException) {
            return null; // si no se puede buscar, se emite normalmente
        }
    }

    private function apply(ElectronicDocument $document, ProviderDocument $result): void
    {
        $document->provider_id ??= $result->providerId;
        $document->number ??= $result->number;
        $document->status = $result->status
            ?? ($document->provider_id ? DocumentStatus::Pendiente : $document->status);
        $document->sunat_code = $result->sunatCode ?? $document->sunat_code;
        $document->sunat_message = $result->sunatMessage;
        $document->hash = $result->hash ?? $document->hash;
        $document->response_payload = $result->raw;
        $document->save();
    }
}
