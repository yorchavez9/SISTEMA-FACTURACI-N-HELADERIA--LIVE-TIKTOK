<?php

namespace App\Integrations\Facturacion;

use App\Integrations\Facturacion\Contracts\InvoicingProvider;
use App\Integrations\Facturacion\Exceptions\FacturacionException;
use App\Support\Settings;

/**
 * Autoconfiguración tras guardar las credenciales:
 *  1. GET /empresa  → razón social, RUC, régimen, tasa IGV, entorno.
 *  2. GET /series   → verifica que las series configuradas existan y estén activas;
 *                     si no, elige la primera activa de cada tipo.
 * Así el punto de venta puede emitir sin más configuración.
 */
class BillingSetup
{
    /** tipo en el proveedor => [clave de setting, prefijo requerido] */
    private const SERIES = [
        'series_boleta' => ['boleta', 'B'],
        'series_factura' => ['factura', 'F'],
        'series_nc_boleta' => ['nota_credito', 'B'],
        'series_nc_factura' => ['nota_credito', 'F'],
        'series_nd_boleta' => ['nota_debito', 'B'],
        'series_nd_factura' => ['nota_debito', 'F'],
    ];

    public function __construct(private InvoicingProvider $provider) {}

    /**
     * @return array{ok: bool, message: string, warnings: array<int, string>}
     */
    public function sync(): array
    {
        try {
            $company = $this->provider->company();
        } catch (FacturacionException $e) {
            return ['ok' => false, 'message' => 'No se pudo conectar: '.$e->getMessage(), 'warnings' => []];
        }

        Settings::set([
            'company_name' => $company->name,
            'company_ruc' => $company->ruc,
            'tax_regime' => $company->taxRegime,
            'igv_rate' => $company->igvRate ?? '18',
            'environment' => $company->environment,
        ]);

        $warnings = [];
        $changes = [];

        try {
            $available = collect($this->provider->series())->filter(fn ($s) => $s['active'] && $s['series'] !== '');

            foreach (self::SERIES as $key => [$type, $prefix]) {
                $candidates = $available
                    ->where('type', $type)
                    ->filter(fn ($s) => str_starts_with($s['series'], $prefix))
                    ->pluck('series');
                $current = Settings::get($key);

                if ($candidates->contains($current)) {
                    continue;
                }

                if ($candidates->isEmpty()) {
                    $warnings[] = "No hay serie activa de tipo {$type} que empiece con {$prefix} en el proveedor.";

                    continue;
                }

                $changes[$key] = $candidates->sort()->first();
            }

            if ($changes) {
                Settings::set($changes);
            }
        } catch (FacturacionException $e) {
            $warnings[] = 'No se pudieron verificar las series: '.$e->getMessage();
        }

        $env = $company->environment === 'production' ? 'PRODUCCIÓN' : 'beta (pruebas)';
        $message = "Conectado: {$company->name} (RUC {$company->ruc}) · entorno {$env}.";
        if ($changes) {
            $message .= ' Series ajustadas: '.implode(', ', $changes).'.';
        }

        return ['ok' => true, 'message' => $message, 'warnings' => $warnings];
    }
}
