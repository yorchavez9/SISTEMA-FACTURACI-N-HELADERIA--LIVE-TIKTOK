<?php

namespace App\Enums;

/** Catálogo 01 SUNAT (tipos usados por el sistema). */
enum ElectronicDocumentType: string
{
    case Factura = '01';
    case Boleta = '03';
    case NotaCredito = '07';
    case NotaDebito = '08';

    public function label(): string
    {
        return match ($this) {
            self::Factura => 'Factura',
            self::Boleta => 'Boleta',
            self::NotaCredito => 'Nota de crédito',
            self::NotaDebito => 'Nota de débito',
        };
    }

    public function isNote(): bool
    {
        return $this === self::NotaCredito || $this === self::NotaDebito;
    }

    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
