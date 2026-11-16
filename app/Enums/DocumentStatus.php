<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Borrador = 'borrador';              // creado localmente, aún no llega al proveedor
    case ErrorEnvio = 'error_envio';         // proveedor caído o timeout: se reintenta solo
    case Error = 'error';                    // el proveedor rechazó los datos o las credenciales
    case Pendiente = 'pendiente';            // registrado en el proveedor, pendiente de envío a SUNAT
    case Enviado = 'enviado';                // enviado a SUNAT, esperando CDR
    case Aceptado = 'aceptado';
    case Rechazado = 'rechazado';
    case Anulado = 'anulado';
    case RevisionManual = 'revision_manual'; // agotó reintentos: revisar en el panel del proveedor

    public function label(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::ErrorEnvio => 'Error de envío',
            self::Error => 'Error',
            self::Pendiente => 'Pendiente',
            self::Enviado => 'Enviado',
            self::Aceptado => 'Aceptado',
            self::Rechazado => 'Rechazado',
            self::Anulado => 'Anulado',
            self::RevisionManual => 'Revisión manual',
        };
    }

    /** Estados que se consultan periódicamente en el proveedor. */
    public static function awaitingSunat(): array
    {
        return [self::Pendiente, self::Enviado];
    }

    /** Estados que requieren atención del usuario. */
    public static function needsAttention(): array
    {
        return [self::Error, self::Rechazado, self::RevisionManual];
    }

    /** Puede corregirse con PUT (el proveedor lo reenvía solo). */
    public function isCorrectable(): bool
    {
        return in_array($this, [self::Rechazado, self::Pendiente], true);
    }

    /** El comprobante nunca llegó a ser válido ante SUNAT. */
    public function neverValid(): bool
    {
        return in_array($this, [self::Borrador, self::ErrorEnvio, self::Error, self::Rechazado, self::RevisionManual], true);
    }

    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
