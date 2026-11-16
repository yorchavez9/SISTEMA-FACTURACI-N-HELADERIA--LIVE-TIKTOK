<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\IdentityDocType;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registro y anulación de ventas. No conoce la facturación electrónica.
 */
class SaleService
{
    /** Monto desde el cual una boleta debe identificar al cliente (S/). */
    public const BOLETA_ID_THRESHOLD = 700;

    public function __construct(private StockService $stock) {}

    /**
     * @param  array{items: array<int, array{product_id: int, quantity: numeric}>, payment_method: string,
     *     amount_received?: numeric|null, document_type: string, customer_id?: int|null}  $data
     */
    public function create(array $data, User $user): Sale
    {
        $documentType = DocumentType::from($data['document_type']);
        $paymentMethod = PaymentMethod::from($data['payment_method']);
        $customer = ! empty($data['customer_id']) ? Customer::find($data['customer_id']) : null;

        // Agrupa líneas repetidas del mismo producto.
        $quantities = [];
        foreach ($data['items'] as $line) {
            $id = (int) $line['product_id'];
            $quantities[$id] = round(($quantities[$id] ?? 0) + (float) $line['quantity'], 2);
        }

        return DB::transaction(function () use ($quantities, $documentType, $paymentMethod, $customer, $data, $user) {
            $products = Product::query()
                ->whereIn('id', array_keys($quantities))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $allowNegative = Settings::bool('allow_negative_stock');
            $lines = [];
            $total = 0.0;

            foreach ($quantities as $productId => $qty) {
                /** @var Product|null $product */
                $product = $products->get($productId);

                if (! $product || ! $product->active) {
                    throw ValidationException::withMessages(['items' => 'Uno de los productos ya no está disponible.']);
                }

                if ($product->track_stock && ! $allowNegative && (float) $product->stock < $qty) {
                    throw ValidationException::withMessages([
                        'items' => "Stock insuficiente de {$product->name} (disponible: ".(float) $product->stock.').',
                    ]);
                }

                $lineTotal = round((float) $product->price * $qty, 2);
                $total += $lineTotal;
                $lines[] = [$product, $qty, $lineTotal];
            }

            $total = round($total, 2);
            $this->ensureDocumentRules($documentType, $customer, $total);

            [$received, $change] = $this->resolvePayment($paymentMethod, $data['amount_received'] ?? null, $total);

            $sale = Sale::create([
                'user_id' => $user->id,
                'customer_id' => $customer?->id,
                'sold_at' => now(),
                'total' => $total,
                'payment_method' => $paymentMethod,
                'amount_received' => $received,
                'change' => $change,
                'document_type' => $documentType,
                'status' => SaleStatus::Completada,
            ]);

            foreach ($lines as [$product, $qty, $lineTotal]) {
                $sale->items()->create([
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'unit' => $product->unit,
                    'tax_affectation' => $product->tax_affectation,
                    'unit_price' => $product->price,
                    'quantity' => $qty,
                    'total' => $lineTotal,
                ]);

                $this->stock->move($product, -$qty, StockMovementType::Sale, $user, $sale->id);
            }

            return $sale->load('items', 'customer');
        });
    }

    /** Anula la venta y repone el stock. La anulación del comprobante se hace antes, aparte. */
    public function void(Sale $sale, User $user, string $reason): void
    {
        if ($sale->isVoided()) {
            throw ValidationException::withMessages(['reason' => 'La venta ya está anulada.']);
        }

        DB::transaction(function () use ($sale, $user, $reason) {
            $sale->update([
                'status' => SaleStatus::Anulada,
                'voided_at' => now(),
                'voided_by' => $user->id,
                'void_reason' => $reason,
            ]);

            foreach ($sale->items as $item) {
                $product = Product::withTrashed()->lockForUpdate()->find($item->product_id);
                if ($product) {
                    $this->stock->move($product, (float) $item->quantity, StockMovementType::Void, $user, $sale->id, 'Anulación '.$sale->code);
                }
            }
        });
    }

    /**
     * Repone stock de ítems devueltos (nota de crédito por devolución parcial).
     *
     * @param  array<int, float>  $quantities  [sale_item_id => cantidad devuelta]
     */
    public function restockItems(Sale $sale, array $quantities, User $user, string $note): void
    {
        DB::transaction(function () use ($sale, $quantities, $user, $note) {
            foreach ($sale->items as $item) {
                $qty = (float) ($quantities[$item->id] ?? 0);
                if ($qty <= 0) {
                    continue;
                }
                $product = Product::withTrashed()->lockForUpdate()->find($item->product_id);
                if ($product) {
                    $this->stock->move($product, $qty, StockMovementType::Void, $user, $sale->id, $note);
                }
            }
        });
    }

    /** Reglas SUNAT que dependen del cliente y del total. */
    public function ensureDocumentRules(DocumentType $type, ?Customer $customer, float $total): void
    {
        if ($type === DocumentType::Factura) {
            if (! Settings::canIssueFactura()) {
                throw ValidationException::withMessages(['document_type' => 'El régimen Nuevo RUS solo permite emitir boletas.']);
            }
            if (! $customer || $customer->doc_type !== IdentityDocType::Ruc) {
                throw ValidationException::withMessages(['customer_id' => 'Para emitir factura seleccione un cliente con RUC.']);
            }
            if (blank($customer->address)) {
                throw ValidationException::withMessages(['customer_id' => 'El cliente necesita una dirección para emitir factura.']);
            }
        }

        if ($type === DocumentType::Boleta && $total >= self::BOLETA_ID_THRESHOLD && ! $customer) {
            throw ValidationException::withMessages([
                'customer_id' => 'Boletas desde S/ '.self::BOLETA_ID_THRESHOLD.' requieren identificar al cliente (DNI).',
            ]);
        }
    }

    /** @return array{0: float|null, 1: float|null} [monto recibido, vuelto] */
    private function resolvePayment(PaymentMethod $method, mixed $received, float $total): array
    {
        if ($method !== PaymentMethod::Efectivo) {
            return [null, null];
        }

        $received = $received === null || $received === '' ? $total : round((float) $received, 2);

        if ($received < $total) {
            throw ValidationException::withMessages(['amount_received' => 'El monto recibido es menor al total.']);
        }

        return [$received, round($received - $total, 2)];
    }
}
