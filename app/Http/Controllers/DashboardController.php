<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Models\ElectronicDocument;
use App\Models\Product;
use App\Services\ReportService;
use App\Support\Settings;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(ReportService $reports): Response
    {
        $today = today();

        return Inertia::render('dashboard', [
            'summary' => $reports->summary($today, $today),
            'yesterday' => $reports->summary($today->copy()->subDay(), $today->copy()->subDay()),
            'byPaymentMethod' => $reports->byPaymentMethod($today, $today),
            'byHour' => $reports->byHour($today),
            'topProducts' => $reports->topProducts($today, $today, 5),
            'alerts' => [
                'billingConfigured' => Settings::billingConfigured(),
                'documentsNeedingAttention' => ElectronicDocument::query()
                    ->whereIn('status', DocumentStatus::needsAttention())
                    ->where('issued_at', '>=', now()->subDays(15))
                    ->count(),
                'documentsDelayed' => ElectronicDocument::query()
                    ->whereIn('status', [...DocumentStatus::awaitingSunat(), DocumentStatus::ErrorEnvio])
                    ->where('created_at', '<=', now()->subHour())
                    ->count(),
                'lowStock' => Product::query()->active()->lowStock()->orderBy('stock')->limit(8)->get(['id', 'name', 'stock', 'stock_min']),
            ],
        ]);
    }
}
