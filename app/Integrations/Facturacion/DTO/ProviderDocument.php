<?php

namespace App\Integrations\Facturacion\DTO;

use App\Enums\DocumentStatus;

/** Comprobante tal como lo devuelve el proveedor, normalizado. */
final class ProviderDocument
{
    public function __construct(
        public readonly ?string $providerId,
        public readonly ?int $number,
        public readonly ?DocumentStatus $status,
        public readonly ?string $sunatCode = null,
        public readonly ?string $sunatMessage = null,
        public readonly ?string $hash = null,
        /** @var array<string, mixed> */
        public readonly array $raw = [],
    ) {}
}
