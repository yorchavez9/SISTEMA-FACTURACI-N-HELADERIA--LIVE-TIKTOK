<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\SaleItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** Consultas agregadas sobre ventas completadas (excluye anuladas). */
class ReportService
{
    /** @return array{count: int, total: float, average: float} */
    public function summary(CarbonInterface $from, CarbonInterface $to): array
    {
        $row = $this->sales($from, $to)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(total), 0) as total')
            ->first();

        $count = (int) $row->count;
        $total = round((float) $row->total, 2);

        return ['count' => $count, 'total' => $total, 'average' => $count ? round($total / $count, 2) : 0.0];
    }

    /** @return array<int, array{date: string, count: int, total: float}> */
    public function byDay(CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->sales($from, $to)
            ->selectRaw('DATE(sold_at) as date, COUNT(*) as count, SUM(total) as total')
            ->groupBy(DB::raw('DATE(sold_at)'))
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => ['date' => (string) $r->date, 'count' => (int) $r->count, 'total' => round((float) $r->total, 2)])
            ->all();
    }

    /** @return array<int, array{method: string, label: string, count: int, total: float}> */
    public function byPaymentMethod(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = $this->sales($from, $to)
            ->selectRaw('payment_method, COUNT(*) as count, SUM(total) as total')
            ->groupBy('payment_method')
            ->get()
            ->keyBy(fn ($r) => $r->payment_method instanceof PaymentMethod ? $r->payment_method->value : $r->payment_method);

        // Siempre los 4 métodos, aunque no tengan ventas (útil como cierre del día).
        return array_map(function (PaymentMethod $m) use ($rows) {
            $r = $rows->get($m->value);

            return [
                'method' => $m->value,
                'label' => $m->label(),
                'count' => (int) ($r->count ?? 0),
                'total' => round((float) ($r->total ?? 0), 2),
            ];
        }, PaymentMethod::cases());
    }

    /** @return array<int, array{product_id: int, name: string, quantity: float, total: float}> */
    public function topProducts(CarbonInterface $from, CarbonInterface $to, int $limit = 10): array
    {
        return SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', SaleStatus::Completada->value)
            ->whereBetween('sales.sold_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw('sale_items.product_id, MAX(sale_items.product_name) as name, SUM(sale_items.quantity) as quantity, SUM(sale_items.total) as total')
            ->groupBy('sale_items.product_id')
            ->orderByDesc('quantity')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'product_id' => (int) $r->product_id,
                'name' => (string) $r->name,
                'quantity' => round((float) $r->quantity, 2),
                'total' => round((float) $r->total, 2),
            ])
            ->all();
    }

    /** Ventas del día agrupadas por hora (se agrupa en PHP para no depender del motor SQL). */
    public function byHour(CarbonInterface $day): array
    {
        $hours = array_fill(0, 24, 0.0);

        $this->sales($day, $day)->get(['sold_at', 'total'])->each(function (Sale $sale) use (&$hours) {
            $hours[(int) $sale->sold_at->format('G')] += (float) $sale->total;
        });

        return collect($hours)
            ->map(fn ($total, $hour) => ['hour' => sprintf('%02d:00', $hour), 'total' => round($total, 2)])
            ->filter(fn ($row, $hour) => $hour >= 8 && $hour <= 23 || $row['total'] > 0)
            ->values()
            ->all();
    }

    private function sales(CarbonInterface $from, CarbonInterface $to)
    {
        return Sale::query()
            ->where('status', SaleStatus::Completada->value)
            ->whereBetween('sold_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
    }
}
