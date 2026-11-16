<?php

namespace App\Integrations\Facturacion\DTO;

use App\Enums\DocumentStatus;

/** Comunicación de baja (RA) o resumen de anulación (RC), normalizado. */
final class ProviderVoiding
{
    public function __construct(
        public readonly ?string $providerId,
        public readonly ?string $identifier,
        public readonly ?string $ticket,
        public readonly ?DocumentStatus $status,
        public readonly ?string $sunatCode = null,
        public readonly ?string $sunatMessage = null,
        /** @var array<string, mixed> */
        public readonly array $raw = [],
    ) {}
}
