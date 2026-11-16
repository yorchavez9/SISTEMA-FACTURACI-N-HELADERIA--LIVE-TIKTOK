<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Enums\PaymentMethod;
use App\Integrations\Facturacion\ElectronicDocumentService;
use App\Models\Sale;
use App\Services\SaleService;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SaleController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $filters = $request->validate([
            'date' => ['nullable', 'date'],
            'payment_method' => ['nullable', 'string'],
            'status' => ['nullable', 'in:completada,anulada'],
        ]);
        // El cajero solo ve las ventas del día.
        $date = $user->isAdmin() ? ($filters['date'] ?? today()->toDateString()) : today()->toDateString();

        $sales = Sale::query()
            ->with(['user:id,name', 'customer:id,name,doc_number', 'invoice:id,sale_id,type,series,number,status'])
            ->whereDate('sold_at', $date)
            ->when($filters['payment_method'] ?? null, fn ($q, $m) => $q->where('payment_method', $m))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->latest('sold_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('sales/index', [
            'sales' => $sales,
            'filters' => ['date' => $date] + $filters,
            'paymentMethods' => PaymentMethod::options(),
            'canFilterDate' => $user->isAdmin(),
        ]);
    }

    public function show(Request $request, Sale $sale): Response
    {
        abort_unless($request->user()->isAdmin() || $sale->sold_at->isToday(), 403);

        $sale->load(['items', 'user:id,name', 'customer', 'voidedBy:id,name', 'documents' => fn ($q) => $q->orderBy('id')]);

        return Inertia::render('sales/show', [
            'sale' => $sale,
            'canEmit' => Settings::billingConfigured()
                && ! $sale->isVoided()
                && $sale->invoice === null
                && $sale->sold_at->isToday(),
            'canIssueFactura' => Settings::canIssueFactura(),
        ]);
    }

    /** Anula la venta. Si tiene comprobante aceptado, primero emite la nota de crédito. */
    public function void(Request $request, Sale $sale, SaleService $sales, ElectronicDocumentService $documents): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:250']]);

        if ($sale->isVoided()) {
            return back()->with('error', 'La venta ya está anulada.');
        }

        $invoice = $sale->invoice;

        if ($invoice) {
            if (in_array($invoice->status, DocumentStatus::awaitingSunat(), true)) {
                return back()->with('error', 'El comprobante aún está en proceso en SUNAT. Actualice su estado e intente de nuevo.');
            }

            if ($invoice->status === DocumentStatus::Aceptado) {
                // Lanza FacturacionException si falla: la venta no se anula.
                $creditNote = $documents->voidWithCreditNote($invoice, $data['reason']);
                $sales->void($sale, $request->user(), $data['reason']);

                return back()->with('success', "Venta anulada. Nota de crédito {$creditNote->full_number} ({$creditNote->status->label()}).");
            }

            $documents->discard($invoice); // nunca fue válido ante SUNAT
        }

        $sales->void($sale, $request->user(), $data['reason']);

        return back()->with('success', 'Venta anulada y stock repuesto.');
    }

    /** Ticket interno imprimible (ventas sin comprobante electrónico). */
    public function ticket(Request $request, Sale $sale): Response
    {
        $sale->load('items', 'user:id,name', 'customer');

        return Inertia::render('sales/ticket', [
            'sale' => $sale,
            // embed=1: se muestra dentro del visor del POS (sin imprimir automáticamente).
            'embed' => $request->boolean('embed'),
            'business' => [
                'name' => Settings::get('business_name'),
                'ruc' => Settings::get('business_ruc'),
                'address' => Settings::get('business_address'),
                'phone' => Settings::get('business_phone'),
                'footer' => Settings::get('ticket_footer'),
            ],
        ]);
    }
}
