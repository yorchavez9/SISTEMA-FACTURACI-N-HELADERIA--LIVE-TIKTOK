<?php

namespace App\Enums;

/** Comprobante elegido al registrar la venta. */
enum DocumentType: string
{
    case Ninguno = 'ninguno';
    case Boleta = 'boleta';
    case Factura = 'factura';

    public function label(): string
    {
        return match ($this) {
            self::Ninguno => 'Sin comprobante',
            self::Boleta => 'Boleta',
            self::Factura => 'Factura',
        };
    }

    public function electronicType(): ?ElectronicDocumentType
    {
        return match ($this) {
            self::Ninguno => null,
            self::Boleta => ElectronicDocumentType::Boleta,
            self::Factura => ElectronicDocumentType::Factura,
        };
    }

    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
