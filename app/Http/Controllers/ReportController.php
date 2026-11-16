<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function index(Request $request, ReportService $reports): Response
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ], [], ['from' => 'desde', 'to' => 'hasta']);

        $to = CarbonImmutable::parse($data['to'] ?? today());
        $from = CarbonImmutable::parse($data['from'] ?? $to->startOfMonth());

        if ($from->diffInDays($to) > 366) {
            $from = $to->subYear();
        }

        return Inertia::render('reports/index', [
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'summary' => $reports->summary($from, $to),
            'byDay' => $reports->byDay($from, $to),
            'byPaymentMethod' => $reports->byPaymentMethod($from, $to),
            'topProducts' => $reports->topProducts($from, $to, 20),
        ]);
    }
}
