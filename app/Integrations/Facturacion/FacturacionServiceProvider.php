<?php

namespace App\Integrations\Facturacion;

use App\Integrations\Facturacion\Contracts\InvoicingProvider;
use App\Integrations\Facturacion\Kodevo\KodevoClient;
use App\Integrations\Facturacion\Kodevo\KodevoProvider;
use App\Support\Settings;
use Illuminate\Support\ServiceProvider;

/** Registra el proveedor de facturación. Para cambiar de proveedor, cambiar solo este binding. */
class FacturacionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(InvoicingProvider::class, function () {
            $settings = Settings::all();

            if (blank($settings['api_key']) || blank($settings['api_secret'])) {
                return new NotConfiguredProvider;
            }

            return new KodevoProvider(new KodevoClient(
                baseUrl: $settings['api_base_url'] ?: config('facturacion.base_url'),
                apiKey: $settings['api_key'],
                apiSecret: $settings['api_secret'],
                timeout: config('facturacion.timeout'),
            ));
        });
    }
}
