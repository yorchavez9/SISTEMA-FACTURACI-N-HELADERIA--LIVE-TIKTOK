<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Enums\ElectronicDocumentType;
use App\Enums\NoteReason;
use App\Integrations\Facturacion\ElectronicDocumentService;
use App\Integrations\Facturacion\PayloadBuilder;
use App\Models\ElectronicDocument;
use App\Services\SaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Acciones administrativas sobre un comprobante aceptado:
 * nota de crédito, nota de débito y comunicación de baja / resumen de anulación.
 */
class DocumentActionController extends Controller
{
    /** Datos para los diálogos (ítems de la venta y saldo acreditable). */
    public function noteData(ElectronicDocument $document, ElectronicDocumentService $service): JsonResponse
    {
        $document->load('sale.items');
        $credited = $service->creditedTotal($document);

        return response()->json([
            'document' => [
                'id' => $document->id,
                'number' => $document->full_number,
                'type' => $document->type->value,
                'type_label' => $document->type->label(),
                'total' => (float) $document->total,
                'issued_at' => $document->issued_at->toIso8601String(),
                'customer' => $document->customer_snapshot,
                'sale_voided' => $document->sale?->isVoided() ?? false,
            ],
            'items' => $document->sale?->items->map(fn ($i) => [
                'id' => $i->id,
                'name' => $i->product_name,
                'quantity' => (float) $i->quantity,
                'unit_price' => (float) $i->unit_price,
            ])->values() ?? [],
            'credited' => $credited,
            'remaining' => round((float) $document->total - $credited, 2),
            'actions' => $document->availableActions(),
            'void_kind' => $document->voidingKind(),
            'credit_reasons' => NoteReason::options(NoteReason::CREDIT),
            'debit_reasons' => NoteReason::options(NoteReason::DEBIT),
            'annul_codes' => NoteReason::CREDIT_ANNULS,
        ]);
    }

    public function creditNote(Request $request, ElectronicDocument $document, ElectronicDocumentService $service, SaleService $sales): RedirectResponse
    {
        $this->ensureAllowed($document, 'credit_note');

        $data = $request->validate([
            'code' => ['required', Rule::in(array_keys(NoteReason::CREDIT))],
            'reason' => ['required', 'string', 'min:3', 'max:250'],
            'quantities' => ['array'],
            'quantities.*' => ['numeric', 'min:0', 'max:999'],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:99999'],
            'void_sale' => ['boolean'],
            'restock' => ['boolean'],
        ], [], ['code' => 'motivo', 'reason' => 'sustento', 'amount' => 'monto']);

        $document->load('sale.items');
        $sale = $document->sale;
        $mode = NoteReason::CREDIT[$data['code']]['mode'];
        $remaining = round((float) $document->total - $service->creditedTotal($document), 2);
        $builder = app(PayloadBuilder::class);

        $quantities = null;
        if ($mode === 'full') {
            $items = $builder->saleItems($sale);
            $total = (float) $document->total;
        } elseif ($mode === 'items') {
            $quantities = [];
            foreach ($sale->items as $item) {
                $qty = round((float) ($data['quantities'][$item->id] ?? 0), 2);
                if ($qty > (float) $item->quantity) {
                    throw ValidationException::withMessages(['quantities' => "No puede devolver más de lo vendido de {$item->product_name}."]);
                }
                if ($qty > 0) {
                    $quantities[$item->id] = $qty;
                }
            }
            if ($quantities === []) {
                throw ValidationException::withMessages(['quantities' => 'Indique al menos una cantidad a devolver.']);
            }
            $items = $builder->saleItems($sale, $quantities);
            $total = round(array_sum(array_map(fn ($i) => $i['cantidad'] * $i['precio_unitario'], $items)), 2);
        } else {
            if (empty($data['amount'])) {
                throw ValidationException::withMessages(['amount' => 'Ingrese el monto de la nota.']);
            }
            $total = round((float) $data['amount'], 2);
            $items = $builder->amountItem($data['reason'], $total);
        }

        if ($total > $remaining + 0.001) {
            throw ValidationException::withMessages(['amount' => 'El monto supera el saldo del comprobante (S/ '.number_format($remaining, 2).').']);
        }

        $note = $service->issueNote($document, ElectronicDocumentType::NotaCredito, $data['code'], $data['reason'], $items);

        $extra = '';
        if (in_array($data['code'], NoteReason::CREDIT_ANNULS, true)) {
            $document->update(['status' => DocumentStatus::Anulado]);
            if (($data['void_sale'] ?? false) && ! $sale->isVoided()) {
                $sales->void($sale, $request->user(), "NC {$note->full_number}: {$data['reason']}");
                $extra = ' Venta anulada y stock repuesto.';
            }
        } elseif ($mode === 'items' && ($data['restock'] ?? false)) {
            $sales->restockItems($sale, $quantities, $request->user(), "Devolución NC {$note->full_number}");
            $extra = ' Stock repuesto.';
        }

        return back()->with(
            $note->status->neverValid() ? 'error' : 'success',
            "Nota de crédito {$note->full_number}: {$note->status->label()}.{$extra}"
        );
    }

    public function debitNote(Request $request, ElectronicDocument $document, ElectronicDocumentService $service): RedirectResponse
    {
        $this->ensureAllowed($document, 'debit_note');

        $data = $request->validate([
            'code' => ['required', Rule::in(array_keys(NoteReason::DEBIT))],
            'reason' => ['required', 'string', 'min:3', 'max:250'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999'],
        ], [], ['code' => 'motivo', 'reason' => 'descripción', 'amount' => 'monto']);

        $items = app(PayloadBuilder::class)->amountItem($data['reason'], (float) $data['amount']);
        $note = $service->issueNote($document, ElectronicDocumentType::NotaDebito, $data['code'], $data['reason'], $items);

        return back()->with(
            $note->status->neverValid() ? 'error' : 'success',
            "Nota de débito {$note->full_number}: {$note->status->label()}."
        );
    }

    public function voiding(Request $request, ElectronicDocument $document, ElectronicDocumentService $service, SaleService $sales): RedirectResponse
    {
        $this->ensureAllowed($document, 'void');

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:100'],
            'void_sale' => ['boolean'],
        ], [], ['reason' => 'motivo']);

        $voiding = $service->requestVoiding($document, $data['reason'], $request->user()->id);

        $extra = '';
        $sale = $document->sale;
        if (($data['void_sale'] ?? false) && $document->isBase() && $sale && ! $sale->isVoided()) {
            $sales->void($sale, $request->user(), "{$voiding->kind} {$document->full_number}: {$data['reason']}");
            $extra = ' Venta anulada y stock repuesto.';
        }

        $id = $voiding->identifier ? " {$voiding->identifier}" : '';

        return back()->with('success', "{$voiding->kindLabel()}{$id} enviada: {$voiding->status->label()}. El comprobante quedará anulado cuando SUNAT la acepte.{$extra}");
    }

    private function ensureAllowed(ElectronicDocument $document, string $action): void
    {
        $allowed = $document->availableActions()[$action];
        if ($allowed !== true) {
            throw ValidationException::withMessages(['document' => $allowed]);
        }
    }
}
