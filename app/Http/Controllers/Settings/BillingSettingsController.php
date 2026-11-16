<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Integrations\Facturacion\BillingSetup;
use App\Integrations\Facturacion\Contracts\InvoicingProvider;
use App\Integrations\Facturacion\Exceptions\FacturacionException;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BillingSettingsController extends Controller
{
    public function edit(): Response
    {
        $s = Settings::all();

        return Inertia::render('settings/billing', [
            'settings' => [
                'api_base_url' => $s['api_base_url'] ?: config('facturacion.base_url'),
                'api_key' => $s['api_key'],
                'has_secret' => filled($s['api_secret']),
                'api_branch_id' => $s['api_branch_id'],
                'series_boleta' => $s['series_boleta'],
                'series_factura' => $s['series_factura'],
                'series_nc_boleta' => $s['series_nc_boleta'],
                'series_nc_factura' => $s['series_nc_factura'],
                'series_nd_boleta' => $s['series_nd_boleta'],
                'series_nd_factura' => $s['series_nd_factura'],
                'pdf_format' => $s['pdf_format'],
            ],
            'company' => [
                'name' => $s['company_name'],
                'ruc' => $s['company_ruc'],
                'tax_regime' => $s['tax_regime'],
                'igv_rate' => $s['igv_rate'],
                'environment' => $s['environment'],
            ],
            'configured' => Settings::billingConfigured(),
            // Datos consultados al proveedor solo bajo demanda (botón "Probar conexión").
            'remote' => Inertia::optional(fn () => $this->remote()),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'api_base_url' => ['required', 'url:https'],
            'api_key' => ['required', 'string', 'max:200'],
            'api_secret' => [Rule::requiredIf(! filled(Settings::get('api_secret'))), 'nullable', 'string', 'max:200'],
            'api_branch_id' => ['nullable', 'string', 'max:40'],
            'series_boleta' => ['required', 'regex:/^B[A-Z0-9]{3}$/'],
            'series_factura' => ['required', 'regex:/^F[A-Z0-9]{3}$/'],
            'series_nc_boleta' => ['required', 'regex:/^B[A-Z0-9]{3}$/'],
            'series_nc_factura' => ['required', 'regex:/^F[A-Z0-9]{3}$/'],
            'series_nd_boleta' => ['required', 'regex:/^B[A-Z0-9]{3}$/'],
            'series_nd_factura' => ['required', 'regex:/^F[A-Z0-9]{3}$/'],
            'pdf_format' => ['required', 'in:ticket-80,ticket-58,a4,a5'],
        ], [
            'series_boleta.regex' => 'La serie de boleta debe tener 4 caracteres y empezar con B (ej. B001).',
            'series_factura.regex' => 'La serie de factura debe tener 4 caracteres y empezar con F (ej. F001).',
            'series_nc_boleta.regex' => 'La serie de NC de boleta debe empezar con B (ej. BC01).',
            'series_nc_factura.regex' => 'La serie de NC de factura debe empezar con F (ej. FC01).',
            'series_nd_boleta.regex' => 'La serie de ND de boleta debe empezar con B (ej. BD01).',
            'series_nd_factura.regex' => 'La serie de ND de factura debe empezar con F (ej. FD01).',
        ]);

        if (blank($data['api_secret'] ?? null)) {
            unset($data['api_secret']); // conservar el secret guardado
        }

        Settings::set(array_map(fn ($v) => is_string($v) ? trim($v) : $v, $data));

        // Conecta y autoconfigura al guardar: el POS queda listo para emitir.
        return $this->syncResponse();
    }

    /** Vuelve a leer empresa y series del proveedor. */
    public function test(): RedirectResponse
    {
        return $this->syncResponse();
    }

    private function syncResponse(): RedirectResponse
    {
        // Se resuelve aquí (no por inyección) para usar las credenciales recién guardadas.
        $result = app(BillingSetup::class)->sync();

        if (! $result['ok']) {
            return back()->with('error', 'Configuración guardada, pero '.lcfirst($result['message']));
        }

        $message = $result['message'].' Ya puede emitir boletas y facturas desde el punto de venta.';

        return $result['warnings']
            ? back()->with('success', $message)->with('error', implode(' ', $result['warnings']))
            : back()->with('success', $message);
    }

    private function remote(): array
    {
        $provider = app(InvoicingProvider::class);
        $result = ['branches' => [], 'series' => [], 'usage' => null, 'error' => null];

        try {
            $result['branches'] = $provider->branches();
            $result['series'] = $provider->series();
            $result['usage'] = $provider->usage();
        } catch (FacturacionException $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }
}
