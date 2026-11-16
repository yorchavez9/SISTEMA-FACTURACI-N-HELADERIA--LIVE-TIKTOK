<?php

namespace App\Integrations\Facturacion\Kodevo;

use App\Enums\DocumentStatus;
use App\Integrations\Facturacion\DTO\CompanyInfo;
use App\Integrations\Facturacion\DTO\ProviderDocument;
use App\Integrations\Facturacion\DTO\ProviderVoiding;
use Illuminate\Support\Arr;

/**
 * Traduce las respuestas del proveedor a DTOs locales.
 *
 * Formato real verificado (entorno beta, 2026-11-16):
 *   { "estado": "exito", "mensaje": "OK", "datos": { ... } }
 *   Comprobante: datos.{id, serie, correlativo, numero_completo,
 *                       sunat: {estado, codigo, descripcion, notas, hash_cpe}, archivos: {...}}
 *   Listado:     datos.{datos: [...], paginacion: {...}}  o  datos: [...]
 * Se mantienen claves alternativas como respaldo.
 */
class ResponseMapper
{
    public static function document(array $json): ProviderDocument
    {
        $data = self::payload($json);

        $id = self::first($data, ['id']);
        $number = self::first($data, ['correlativo', 'numero']);
        $status = self::first($data, ['sunat.estado', 'sunat_status', 'estado_sunat']);

        $message = self::first($data, ['sunat.descripcion', 'sunat_message', 'mensaje_sunat']);
        $notes = Arr::get($data, 'sunat.notas');
        if (is_array($notes) && $notes !== []) {
            $message = trim(($message ? $message.' · ' : '').implode(' · ', array_map(
                fn ($n) => is_array($n) ? implode(' ', array_filter($n, 'is_scalar')) : (string) $n,
                $notes
            )));
        }

        return new ProviderDocument(
            providerId: $id !== null ? (string) $id : null,
            number: is_numeric($number) ? (int) $number : null,
            status: self::status(is_string($status) ? $status : null),
            sunatCode: self::stringOrNull(self::first($data, ['sunat.codigo', 'sunat_code'])),
            sunatMessage: self::stringOrNull($message),
            hash: self::stringOrNull(self::first($data, ['sunat.hash_cpe', 'hash'])),
            raw: $json,
        );
    }

    /**
     * Comunicación de baja / resumen:
     *   datos.{id|id_anulacion|id_resumen, identifier, ticket,
     *          estado_sunat|sunat_status, codigo_sunat|sunat_code, descripcion_sunat|sunat_description}
     */
    public static function voiding(array $json): ProviderVoiding
    {
        $data = self::payload($json);
        if (array_is_list($data) && isset($data[0]) && is_array($data[0])) {
            $data = $data[0];
        }

        $id = self::first($data, ['id_anulacion', 'id_resumen', 'id']);
        $status = self::first($data, ['estado_sunat', 'sunat_status', 'sunat.estado']);

        return new ProviderVoiding(
            providerId: $id !== null ? (string) $id : null,
            identifier: self::stringOrNull(self::first($data, ['identifier', 'identificador'])),
            ticket: self::stringOrNull(self::first($data, ['ticket'])),
            status: self::status(is_string($status) ? $status : null),
            sunatCode: self::stringOrNull(self::first($data, ['codigo_sunat', 'sunat_code'])),
            sunatMessage: self::stringOrNull(self::first($data, ['descripcion_sunat', 'sunat_description'])),
            raw: $json,
        );
    }

    public static function company(array $json): CompanyInfo
    {
        $data = self::payload($json);

        return new CompanyInfo(
            ruc: self::stringOrNull(self::first($data, ['ruc'])),
            name: self::stringOrNull(self::first($data, ['razon_social', 'nombre_comercial'])),
            taxRegime: self::stringOrNull(self::first($data, ['tax_regime', 'regimen'])),
            igvRate: self::stringOrNull(self::first($data, ['tasa_igv_vigente', 'igv_rate_override'])),
            environment: self::stringOrNull(self::first($data, ['entorno'])),
            raw: $json,
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function list(array $json): array
    {
        $data = self::payload($json);

        // Paginado: datos.datos
        if (isset($data['datos']) && is_array($data['datos'])) {
            $data = $data['datos'];
        } elseif (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        return array_values(array_filter(array_is_list($data) ? $data : [], 'is_array'));
    }

    public static function status(?string $value): ?DocumentStatus
    {
        if ($value === null) {
            return null;
        }

        return match (mb_strtolower(trim($value))) {
            'aceptado', 'aceptada', 'accepted', 'observado', 'aceptado_con_observaciones' => DocumentStatus::Aceptado,
            'rechazado', 'rechazada', 'rejected' => DocumentStatus::Rechazado,
            'enviado', 'enviada', 'sent', 'procesando', 'en_proceso' => DocumentStatus::Enviado,
            'pendiente', 'pending', 'registrado', 'por_enviar' => DocumentStatus::Pendiente,
            'anulado', 'anulada', 'baja', 'voided' => DocumentStatus::Anulado,
            default => null,
        };
    }

    /** Contenido útil de la respuesta: "datos" (formato real) o "data" (respaldo). */
    private static function payload(array $json): array
    {
        foreach (['datos', 'data'] as $key) {
            if (array_key_exists($key, $json) && is_array($json[$key])) {
                return $json[$key];
            }
        }

        return $json;
    }

    private static function first(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = Arr::get($data, $key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value;
    }
}
