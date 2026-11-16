<?php

namespace App\Console\Commands;

use App\Enums\DocumentStatus;
use App\Integrations\Facturacion\ElectronicDocumentService;
use App\Integrations\Facturacion\Exceptions\FacturacionException;
use App\Models\DocumentVoiding;
use App\Models\ElectronicDocument;
use App\Support\Settings;
use Illuminate\Console\Command;

/**
 * Se ejecuta cada minuto desde el scheduler:
 *  1. Reintenta comprobantes en "error_envio" respetando el backoff.
 *  2. Consulta el estado de comprobantes pendientes/enviados.
 * No requiere worker de colas: solo el cron del scheduler.
 */
class SyncElectronicDocuments extends Command
{
    protected $signature = 'facturacion:sincronizar {--limit=30}';

    protected $description = 'Reintenta envíos fallidos y actualiza el estado SUNAT de los comprobantes';

    public function handle(ElectronicDocumentService $service): int
    {
        if (! Settings::billingConfigured()) {
            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        $backoff = config('facturacion.backoff_minutes');

        $retries = ElectronicDocument::query()
            ->where('status', DocumentStatus::ErrorEnvio)
            ->orderBy('last_attempt_at')
            ->limit($limit)
            ->get()
            ->filter(function (ElectronicDocument $doc) use ($backoff) {
                $wait = $backoff[min($doc->attempts, count($backoff) - 1)] ?? 15;

                return ! $doc->last_attempt_at || $doc->last_attempt_at->lte(now()->subMinutes($wait));
            });

        foreach ($retries as $doc) {
            $service->send($doc);
            $this->line("Reintento {$doc->full_number}: {$doc->status->value}");
        }

        $pending = ElectronicDocument::query()
            ->whereIn('status', DocumentStatus::awaitingSunat())
            ->whereNotNull('provider_id')
            ->where('issued_at', '>=', now()->subDays(config('facturacion.refresh_days')))
            ->where(fn ($q) => $q->whereNull('last_checked_at')->orWhere('last_checked_at', '<=', now()->subMinute()))
            ->orderBy('last_checked_at')
            ->limit($limit)
            ->get();

        foreach ($pending as $doc) {
            try {
                $service->refresh($doc);
                $this->line("Estado {$doc->full_number}: {$doc->status->value}");
            } catch (FacturacionException $e) {
                $this->warn("{$doc->full_number}: {$e->getMessage()}");
            }
        }

        // Comunicaciones de baja / resúmenes de anulación en curso (cada 5 minutos como máximo).
        $voidings = DocumentVoiding::query()
            ->whereIn('status', DocumentStatus::awaitingSunat())
            ->where('created_at', '>=', now()->subDays(10))
            ->where(fn ($q) => $q->whereNull('last_checked_at')->orWhere('last_checked_at', '<=', now()->subMinutes(5)))
            ->limit($limit)
            ->get();

        foreach ($voidings as $voiding) {
            try {
                $service->refreshVoiding($voiding);
                $this->line("{$voiding->kind} {$voiding->identifier}: {$voiding->status->value}");
            } catch (FacturacionException $e) {
                $voiding->update(['last_checked_at' => now()]);
                $this->warn("{$voiding->kind} {$voiding->identifier}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
