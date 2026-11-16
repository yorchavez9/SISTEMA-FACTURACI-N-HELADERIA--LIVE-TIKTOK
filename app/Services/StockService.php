<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class StockService
{
    /**
     * Aplica un movimiento de stock. Debe llamarse dentro de una transacción
     * con el producto bloqueado (lockForUpdate) cuando hay concurrencia.
     */
    public function move(Product $product, float $quantity, StockMovementType $type, ?User $user, ?int $saleId = null, ?string $note = null): void
    {
        if (! $product->track_stock) {
            return;
        }

        $product->stock = round((float) $product->stock + $quantity, 2);
        $product->save();

        StockMovement::create([
            'product_id' => $product->id,
            'type' => $type,
            'quantity' => $quantity,
            'stock_after' => $product->stock,
            'sale_id' => $saleId,
            'user_id' => $user?->id,
            'note' => $note,
        ]);
    }

    /** Ajuste manual: fija el stock al valor contado. */
    public function adjustTo(Product $product, float $newStock, User $user, ?string $note): void
    {
        DB::transaction(function () use ($product, $newStock, $user, $note) {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);
            $delta = round($newStock - (float) $product->stock, 2);

            if ($delta == 0.0) {
                return;
            }

            $this->move($product, $delta, StockMovementType::Adjustment, $user, null, $note);
        });
    }
}
