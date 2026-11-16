<?php

namespace App\Integrations\Facturacion\Exceptions;

use RuntimeException;

/** Error base de la integración con el proveedor de facturación. */
class FacturacionException extends RuntimeException
{
    /** @param array<string, mixed> $context respuesta del proveedor (para guardar/registrar) */
    public function __construct(string $message, public readonly array $context = [], int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
