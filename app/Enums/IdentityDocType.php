<?php

namespace App\Enums;

/** Catálogo 06 SUNAT (subconjunto). */
enum IdentityDocType: string
{
    case SinDocumento = '0';
    case Dni = '1';
    case CarnetExtranjeria = '4';
    case Ruc = '6';
    case Pasaporte = '7';

    public function label(): string
    {
        return match ($this) {
            self::SinDocumento => 'Sin documento',
            self::Dni => 'DNI',
            self::CarnetExtranjeria => 'C. Extranjería',
            self::Ruc => 'RUC',
            self::Pasaporte => 'Pasaporte',
        };
    }

    /** Tipos permitidos al registrar clientes. */
    public static function forCustomers(): array
    {
        return [self::Dni, self::Ruc, self::CarnetExtranjeria, self::Pasaporte];
    }

    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::forCustomers());
    }
}
