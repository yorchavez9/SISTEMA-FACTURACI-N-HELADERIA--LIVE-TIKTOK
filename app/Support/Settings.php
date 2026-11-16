<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Configuración del sistema guardada en la tabla `settings`.
 * Las credenciales del API se guardan cifradas con APP_KEY.
 */
class Settings
{
    private const CACHE_KEY = 'app_settings';

    /** Claves que se guardan cifradas. */
    private const ENCRYPTED = ['api_key', 'api_secret', 'lookup_token'];

    /** Valores por defecto. */
    public const DEFAULTS = [
        // Negocio (ticket interno)
        'business_name' => 'Mi Heladería',
        'business_ruc' => null,
        'business_address' => null,
        'business_phone' => null,
        'ticket_footer' => '¡Gracias por su compra!',
        // Facturación electrónica
        'api_base_url' => null,
        'api_key' => null,
        'api_secret' => null,
        'api_branch_id' => null,
        'series_boleta' => 'B001',
        'series_factura' => 'F001',
        'series_nc_boleta' => 'BC01',
        'series_nc_factura' => 'FC01',
        'series_nd_boleta' => 'BD01',
        'series_nd_factura' => 'FD01',
        'pdf_format' => 'ticket-80',
        // Datos leídos del proveedor (solo lectura en la UI)
        'company_name' => null,
        'company_ruc' => null,
        'tax_regime' => null,
        'igv_rate' => '18',
        'environment' => null,
        // Parámetros del POS
        'default_document_type' => 'boleta',
        'allow_negative_stock' => '0',
        // Consulta de DNI/RUC (api.json.pe)
        'lookup_base_url' => null,
        'lookup_token' => null,
    ];

    /** @return array<string, string|null> */
    public static function all(): array
    {
        $stored = Cache::rememberForever(self::CACHE_KEY, function () {
            return Setting::query()->pluck('value', 'key')->all();
        });

        $values = array_merge(self::DEFAULTS, $stored);

        foreach (self::ENCRYPTED as $key) {
            if (! empty($values[$key])) {
                try {
                    $values[$key] = Crypt::decryptString($values[$key]);
                } catch (\Throwable) {
                    $values[$key] = null; // APP_KEY cambió: hay que volver a ingresarla
                }
            }
        }

        return $values;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    public static function bool(string $key): bool
    {
        return filter_var(self::get($key), FILTER_VALIDATE_BOOLEAN);
    }

    /** @param array<string, mixed> $values */
    public static function set(array $values): void
    {
        foreach ($values as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            if (in_array($key, self::ENCRYPTED, true) && filled($value)) {
                $value = Crypt::encryptString((string) $value);
            }
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value === null ? null : (string) $value]);
        }

        Cache::forget(self::CACHE_KEY);
    }

    public static function billingConfigured(): bool
    {
        $s = self::all();

        return filled($s['api_base_url']) && filled($s['api_key']) && filled($s['api_secret']);
    }

    /** Nuevo RUS: solo puede emitir boletas. */
    public static function canIssueFactura(): bool
    {
        return self::get('tax_regime') !== 'rus';
    }
}
