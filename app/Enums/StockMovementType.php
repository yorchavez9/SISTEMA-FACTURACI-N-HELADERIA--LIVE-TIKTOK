<?php

namespace App\Enums;

enum StockMovementType: string
{
    case Sale = 'sale';
    case Void = 'void';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Venta',
            self::Void => 'Anulación',
            self::Adjustment => 'Ajuste',
        };
    }
}
