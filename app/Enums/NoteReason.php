<?php

namespace App\Enums;

/**
 * Motivos de notas de crédito (catálogo 09) y débito (catálogo 10) que ofrece el sistema.
 * mode: full = todos los ítems completos; items = cantidades por ítem; amount = monto libre.
 */
final class NoteReason
{
    public const CREDIT = [
        '01' => ['label' => 'Anulación de la operación', 'mode' => 'full'],
        '02' => ['label' => 'Anulación por error en el RUC', 'mode' => 'full'],
        '03' => ['label' => 'Corrección por error en la descripción', 'mode' => 'full'],
        '06' => ['label' => 'Devolución total', 'mode' => 'full'],
        '07' => ['label' => 'Devolución por ítem', 'mode' => 'items'],
        '04' => ['label' => 'Descuento global', 'mode' => 'amount'],
        '05' => ['label' => 'Descuento por ítem', 'mode' => 'amount'],
        '09' => ['label' => 'Disminución en el valor', 'mode' => 'amount'],
    ];

    public const DEBIT = [
        '01' => ['label' => 'Intereses por mora', 'mode' => 'amount'],
        '02' => ['label' => 'Aumento en el valor', 'mode' => 'amount'],
        '03' => ['label' => 'Penalidades / otros conceptos', 'mode' => 'amount'],
    ];

    /** Motivos de NC que anulan la operación completa (permiten anular la venta). */
    public const CREDIT_ANNULS = ['01', '02', '06'];

    public static function options(array $reasons): array
    {
        return collect($reasons)->map(fn ($r, $code) => ['value' => (string) $code, 'label' => "{$code} · {$r['label']}", 'mode' => $r['mode']])->values()->all();
    }
}
