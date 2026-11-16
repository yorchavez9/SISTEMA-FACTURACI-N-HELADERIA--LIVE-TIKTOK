<?php

namespace App\Integrations\Facturacion\Contracts;

use App\Enums\ElectronicDocumentType;
use App\Integrations\Facturacion\DTO\CompanyInfo;
use App\Integrations\Facturacion\DTO\DownloadedFile;
use App\Integrations\Facturacion\DTO\ProviderDocument;
use App\Integrations\Facturacion\DTO\ProviderVoiding;

/**
 * Contrato con el proveedor de facturación electrónica.
 * El resto del sistema depende de esta interfaz, nunca de una implementación concreta.
 *
 * Todos los métodos pueden lanzar:
 *  - ProviderUnavailableException (reintentar)
 *  - ProviderValidationException  (corregir datos)
 *  - ProviderAuthException        (revisar credenciales)
 */
interface InvoicingProvider
{
    public function company(): CompanyInfo;

    /** Consumo del plan del mes, tal como lo devuelve el proveedor. */
    public function usage(): array;

    /** @return array<int, array{id: string, name: string, code: ?string}> */
    public function branches(): array;

    /** @return array<int, array{id: string, type: string, series: string, active: bool}> */
    public function series(): array;

    public function issue(ElectronicDocumentType $type, array $payload): ProviderDocument;

    /** Busca un comprobante ya creado que coincida con el payload (evita duplicados al reintentar). */
    public function findMatching(ElectronicDocumentType $type, array $payload, float $total, array $excludeIds = []): ?ProviderDocument;

    public function find(ElectronicDocumentType $type, string $providerId): ProviderDocument;

    public function resend(ElectronicDocumentType $type, string $providerId): ProviderDocument;

    /** Corrige un comprobante pendiente/rechazado; el proveedor lo reenvía solo. */
    public function update(ElectronicDocumentType $type, string $providerId, array $payload): ProviderDocument;

    /** Solo boletas no aceptadas. */
    public function delete(ElectronicDocumentType $type, string $providerId): void;

    /** @param 'pdf'|'xml'|'cdr' $kind */
    public function download(ElectronicDocumentType $type, string $providerId, string $kind, string $format = 'ticket-80'): DownloadedFile;

    /**
     * RA: POST /anulaciones (facturas y notas). RC: POST /resumenes con "anular" (boletas).
     */
    public function voidDocument(string $kind, array $payload): ProviderVoiding;

    /** GET /anulaciones/{id}/estado o /resumenes/{id}/estado. */
    public function voidingStatus(string $kind, string $providerId): ProviderVoiding;

    /** Consulta directa del CPE en SUNAT. */
    public function consultCpe(array $params): array;
}
