<?php

return [
    /*
    | URL base del API de facturación (proveedor). Puede sobrescribirse desde
    | Configuración > Facturación; este valor es el predeterminado.
    */
    'base_url' => env('FACTURACION_BASE_URL', 'https://sunat.kodevo.es/api/v1'),

    // Segundos de espera por respuesta del proveedor al emitir desde el POS.
    'timeout' => (int) env('FACTURACION_TIMEOUT', 10),

    // Reintentos automáticos de un comprobante que no pudo enviarse (error_envio).
    'max_attempts' => (int) env('FACTURACION_MAX_ATTEMPTS', 5),

    // Minutos de espera antes de cada reintento (índice = número de intento).
    'backoff_minutes' => [1, 2, 5, 10, 15],

    // Durante cuántos días se sigue consultando el estado de un comprobante pendiente.
    'refresh_days' => 7,
];
