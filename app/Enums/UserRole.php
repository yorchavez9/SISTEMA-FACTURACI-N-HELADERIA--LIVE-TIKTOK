<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Cajero = 'cajero';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::Cajero => 'Cajero',
        };
    }

    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
