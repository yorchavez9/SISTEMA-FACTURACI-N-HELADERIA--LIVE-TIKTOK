<?php

use Illuminate\Support\Facades\Schedule;

// Consulta estados SUNAT y reintenta envíos fallidos.
// En producción basta un cron: * * * * * php artisan schedule:run
Schedule::command('facturacion:sincronizar')->everyMinute()->withoutOverlapping();
