<?php

namespace App\Integrations\Consultas;

use App\Support\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Consulta de DNI (RENIEC) y RUC (SUNAT) mediante api.json.pe.
 *   POST {base}/dni  {"dni": "..."}  → data.nombre_completo, ...
 *   POST {base}/ruc  {"ruc": "..."}  → data.nombre_o_razon_social, direccion_completa, estado, condicion
 * Autenticación: Authorization: Bearer <token> (Configuración > Consulta DNI/RUC).
 */
class DocumentLookupService
{
    public const DEFAULT_BASE_URL = 'https://api.json.pe/api';

    /** RUC público usado para probar la conexión (SUNAT). */
    public const TEST_RUC = '20131312955';

    public static function configured(): bool
    {
        return filled(Settings::get('lookup_token'));
    }

    /**
     * @return array{doc_type: string, doc_number: string, name: string, address: ?string, warning: ?string}
     *
     * @throws LookupException
     */
    public function dni(string $dni): array
    {
        $data = $this->post('dni', ['dni' => $dni]);

        // "CASTILLO TERRONES, JOSE PEDRO" → "CASTILLO TERRONES JOSE PEDRO"
        $name = trim((string) ($data['nombre_completo'] ?? ''));
        if ($name === '') {
            $name = trim(implode(' ', array_filter([
                $data['apellido_paterno'] ?? null,
                $data['apellido_materno'] ?? null,
                $data['nombres'] ?? null,
            ])));
        }
        $name = preg_replace('/\s+/', ' ', str_replace(',', ' ', $name));

        if ($name === '') {
            throw new LookupException('No se encontraron datos para el DNI '.$dni.'.', notFound: true);
        }

        return [
            'doc_type' => '1',
            'doc_number' => $dni,
            'name' => mb_strtoupper($name),
            'address' => filled($data['direccion_completa'] ?? null) ? $data['direccion_completa'] : null,
            'warning' => null,
        ];
    }

    /**
     * @return array{doc_type: string, doc_number: string, name: string, address: ?string, warning: ?string}
     *
     * @throws LookupException
     */
    public function ruc(string $ruc): array
    {
        $data = $this->post('ruc', ['ruc' => $ruc]);

        $name = trim((string) ($data['nombre_o_razon_social'] ?? ''));
        if ($name === '') {
            throw new LookupException('No se encontraron datos para el RUC '.$ruc.'.', notFound: true);
        }

        $estado = mb_strtoupper(trim((string) ($data['estado'] ?? '')));
        $condicion = mb_strtoupper(trim((string) ($data['condicion'] ?? '')));
        $warning = null;
        if (($estado && $estado !== 'ACTIVO') || ($condicion && $condicion !== 'HABIDO')) {
            $warning = "Contribuyente {$estado} / {$condicion}.";
        }

        $address = $data['direccion_completa'] ?? $data['direccion'] ?? null;

        return [
            'doc_type' => '6',
            'doc_number' => $ruc,
            'name' => mb_strtoupper($name),
            'address' => filled($address) && trim($address) !== '-' ? trim($address) : null,
            'warning' => $warning,
        ];
    }

    /** @throws LookupException */
    private function post(string $endpoint, array $body): array
    {
        $token = Settings::get('lookup_token');
        if (blank($token)) {
            throw new LookupException('Configure el token de consulta DNI/RUC en Configuración.');
        }

        $baseUrl = rtrim(Settings::get('lookup_base_url') ?: self::DEFAULT_BASE_URL, '/');

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->connectTimeout(4)
                ->timeout(8)
                ->post("{$baseUrl}/{$endpoint}", $body);
        } catch (ConnectionException $e) {
            Log::warning("Consulta {$endpoint}: sin conexión", ['error' => $e->getMessage()]);
            throw new LookupException('No se pudo conectar con el servicio de consulta. Ingrese los datos manualmente.');
        }

        $json = $response->json() ?? [];
        $message = is_string($json['message'] ?? null) ? $json['message'] : null;

        if (in_array($response->status(), [401, 403], true)) {
            throw new LookupException('Token de consulta DNI/RUC inválido o sin saldo'.($message ? ": {$message}" : '.'));
        }

        if ($response->status() === 404 || ($response->successful() && ($json['success'] ?? false) !== true)) {
            throw new LookupException($message && $message !== 'exito' ? $message : 'Documento no encontrado.', notFound: true);
        }

        if (! $response->successful()) {
            Log::warning("Consulta {$endpoint}: HTTP {$response->status()}", ['response' => $json]);
            throw new LookupException($message ?: 'El servicio de consulta respondió con un error.');
        }

        return is_array($json['data'] ?? null) ? $json['data'] : [];
    }
}
