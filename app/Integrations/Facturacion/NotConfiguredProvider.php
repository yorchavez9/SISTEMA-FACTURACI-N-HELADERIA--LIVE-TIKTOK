<?php

namespace App\Integrations\Facturacion;

use App\Enums\ElectronicDocumentType;
use App\Integrations\Facturacion\Contracts\InvoicingProvider;
use App\Integrations\Facturacion\DTO\CompanyInfo;
use App\Integrations\Facturacion\DTO\DownloadedFile;
use App\Integrations\Facturacion\DTO\ProviderDocument;
use App\Integrations\Facturacion\DTO\ProviderVoiding;
use App\Integrations\Facturacion\Exceptions\ProviderAuthException;

/** Se usa cuando no hay credenciales: cualquier llamada falla con un mensaje claro. */
class NotConfiguredProvider implements InvoicingProvider
{
    private function fail(): never
    {
        throw new ProviderAuthException('Configure la API Key y el API Secret en Configuración > Facturación.');
    }

    public function company(): CompanyInfo
    {
        $this->fail();
    }

    public function usage(): array
    {
        $this->fail();
    }

    public function branches(): array
    {
        $this->fail();
    }

    public function series(): array
    {
        $this->fail();
    }

    public function issue(ElectronicDocumentType $type, array $payload): ProviderDocument
    {
        $this->fail();
    }

    public function findMatching(ElectronicDocumentType $type, array $payload, float $total, array $excludeIds = []): ?ProviderDocument
    {
        $this->fail();
    }

    public function find(ElectronicDocumentType $type, string $providerId): ProviderDocument
    {
        $this->fail();
    }

    public function resend(ElectronicDocumentType $type, string $providerId): ProviderDocument
    {
        $this->fail();
    }

    public function update(ElectronicDocumentType $type, string $providerId, array $payload): ProviderDocument
    {
        $this->fail();
    }

    public function delete(ElectronicDocumentType $type, string $providerId): void
    {
        $this->fail();
    }

    public function download(ElectronicDocumentType $type, string $providerId, string $kind, string $format = 'ticket-80'): DownloadedFile
    {
        $this->fail();
    }

    public function voidDocument(string $kind, array $payload): ProviderVoiding
    {
        $this->fail();
    }

    public function voidingStatus(string $kind, string $providerId): ProviderVoiding
    {
        $this->fail();
    }

    public function consultCpe(array $params): array
    {
        $this->fail();
    }
}
