<?php

namespace App\Integrations\Facturacion\DTO;

/** Datos de la empresa registrados en el proveedor (GET /empresa). */
final class CompanyInfo
{
    public function __construct(
        public readonly ?string $ruc,
        public readonly ?string $name,
        public readonly ?string $taxRegime,
        public readonly ?string $igvRate,
        public readonly ?string $environment,
        /** @var array<string, mixed> */
        public readonly array $raw = [],
    ) {}
}
