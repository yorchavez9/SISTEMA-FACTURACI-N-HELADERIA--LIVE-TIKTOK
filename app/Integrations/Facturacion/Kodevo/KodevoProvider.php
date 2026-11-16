<?php

namespace App\Integrations\Facturacion\Kodevo;

use App\Enums\ElectronicDocumentType;
use App\Integrations\Facturacion\Contracts\InvoicingProvider;
use App\Integrations\Facturacion\DTO\CompanyInfo;
use App\Integrations\Facturacion\DTO\DownloadedFile;
use App\Integrations\Facturacion\DTO\ProviderDocument;
use App\Integrations\Facturacion\DTO\ProviderVoiding;
use App\Integrations\Facturacion\Exceptions\ProviderValidationException;
use Illuminate\Support\Facades\Http;

/**
 * Implementación para el API de la colección "API FACTURACION SUNAT PERU".
 * Solo usa los endpoints documentados en la colección.
 */
class KodevoProvider implements InvoicingProvider
{
    public function __construct(private KodevoClient $client) {}

    public function company(): CompanyInfo
    {
        return ResponseMapper::company($this->client->get('empresa'));
    }

    public function usage(): array
    {
        return $this->client->get('suscripcion/uso');
    }

    public function branches(): array
    {
        return array_map(fn (array $b) => [
            'id' => (string) ($b['id'] ?? ''),
            'name' => (string) ($b['nombre'] ?? $b['name'] ?? ''),
            'code' => isset($b['cod_local']) ? (string) $b['cod_local'] : null,
        ], ResponseMapper::list($this->client->get('sucursales')));
    }

    public function series(): array
    {
        return array_map(fn (array $s) => [
            'id' => (string) ($s['id'] ?? ''),
            'type' => (string) ($s['tipo'] ?? $s['type'] ?? ''),
            'series' => (string) ($s['serie'] ?? $s['series'] ?? ''),
            'active' => (bool) ($s['activo'] ?? $s['active'] ?? $s['is_active'] ?? true),
        ], ResponseMapper::list($this->client->get('series')));
    }

    public function issue(ElectronicDocumentType $type, array $payload): ProviderDocument
    {
        return ResponseMapper::document($this->client->post($this->resource($type), $payload));
    }

    /**
     * Busca entre los últimos comprobantes de la serie uno que coincida con el payload
     * (misma fecha, total y documento del cliente) y que no esté ya vinculado localmente.
     */
    public function findMatching(ElectronicDocumentType $type, array $payload, float $total, array $excludeIds = []): ?ProviderDocument
    {
        $rows = ResponseMapper::list($this->client->get($this->resource($type), [
            'serie' => $payload['serie'],
            'por_pagina' => 30,
        ]));

        // Los más antiguos primero: si hubo duplicados, se vincula el original.
        foreach (array_reverse($rows) as $row) {
            if (($row['serie'] ?? null) !== $payload['serie']
                || in_array((string) ($row['id'] ?? ''), $excludeIds, true)
                || substr((string) ($row['fecha_emision'] ?? ''), 0, 10) !== $payload['fecha_emision']
                || abs((float) ($row['totales']['total'] ?? -1) - $total) > 0.009
                || (string) ($row['cliente']['num_doc'] ?? '') !== (string) $payload['cliente']['num_doc']) {
                continue;
            }

            return ResponseMapper::document(['datos' => $row]);
        }

        return null;
    }

    public function find(ElectronicDocumentType $type, string $providerId): ProviderDocument
    {
        return ResponseMapper::document($this->client->get($this->resource($type).'/'.$providerId));
    }

    public function resend(ElectronicDocumentType $type, string $providerId): ProviderDocument
    {
        return ResponseMapper::document($this->client->post($this->resource($type).'/'.$providerId.'/reenviar'));
    }

    public function update(ElectronicDocumentType $type, string $providerId, array $payload): ProviderDocument
    {
        return ResponseMapper::document($this->client->put($this->resource($type).'/'.$providerId, $payload));
    }

    public function delete(ElectronicDocumentType $type, string $providerId): void
    {
        if ($type !== ElectronicDocumentType::Boleta) {
            throw new ProviderValidationException('El API solo permite eliminar boletas no aceptadas.');
        }

        $this->client->delete($this->resource($type).'/'.$providerId);
    }

    public function download(ElectronicDocumentType $type, string $providerId, string $kind, string $format = 'ticket-80'): DownloadedFile
    {
        $query = $kind === 'pdf' ? ['format' => $format] : [];
        $response = $this->client->raw($this->resource($type).'/'.$providerId.'/'.$kind, $query);

        $defaultMime = match ($kind) {
            'pdf' => 'application/pdf',
            'xml' => 'application/xml',
            default => 'application/zip',
        };
        $extension = match ($kind) {
            'pdf' => 'pdf',
            'xml' => 'xml',
            default => 'zip',
        };
        $filename = "{$type->value}-{$providerId}.{$extension}";

        $contentType = strtolower($response->header('Content-Type'));

        // Formato de descarga no documentado: puede ser binario directo o JSON con base64/URL.
        if (str_contains($contentType, 'json')) {
            $json = $response->json() ?? [];
            $data = $json['data'] ?? $json;
            $base64 = $data['contenido'] ?? $data['content'] ?? $data['base64'] ?? $data[$kind] ?? null;
            if (is_string($base64) && ($decoded = base64_decode($base64, true)) !== false) {
                return new DownloadedFile($decoded, $defaultMime, $data['filename'] ?? $data['nombre'] ?? $filename);
            }
            $url = $data['url'] ?? $data['download_url'] ?? null;
            if (is_string($url)) {
                $file = Http::timeout(20)->get($url);

                return new DownloadedFile($file->body(), $file->header('Content-Type') ?: $defaultMime, $filename);
            }

            throw new ProviderValidationException('El proveedor no devolvió el archivo solicitado.', $json);
        }

        $disposition = $response->header('Content-Disposition');
        if ($disposition && preg_match('/filename="?([^";]+)"?/i', $disposition, $m)) {
            $filename = $m[1];
        }

        return new DownloadedFile($response->body(), $contentType ?: $defaultMime, $filename);
    }

    public function voidDocument(string $kind, array $payload): ProviderVoiding
    {
        return ResponseMapper::voiding($this->client->post($kind === 'RC' ? 'resumenes' : 'anulaciones', $payload));
    }

    public function voidingStatus(string $kind, string $providerId): ProviderVoiding
    {
        return ResponseMapper::voiding($this->client->get(($kind === 'RC' ? 'resumenes' : 'anulaciones')."/{$providerId}/estado"));
    }

    public function consultCpe(array $params): array
    {
        return $this->client->get('consultar-cpe', $params);
    }

    private function resource(ElectronicDocumentType $type): string
    {
        return match ($type) {
            ElectronicDocumentType::Factura => 'facturas',
            ElectronicDocumentType::Boleta => 'boletas',
            ElectronicDocumentType::NotaCredito => 'notas-credito',
            ElectronicDocumentType::NotaDebito => 'notas-debito',
        };
    }
}
