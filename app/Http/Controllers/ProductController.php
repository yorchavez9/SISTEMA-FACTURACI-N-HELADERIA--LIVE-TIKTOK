<?php

namespace App\Http\Controllers;

use App\Enums\StockMovementType;
use App\Http\Requests\ProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Services\StockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:active,inactive,low_stock'],
        ]);

        $products = Product::query()
            ->with('category:id,name')
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")))
            ->when($filters['category_id'] ?? null, fn ($q, $c) => $q->where('category_id', $c))
            ->when(($filters['status'] ?? null) === 'active', fn ($q) => $q->where('active', true))
            ->when(($filters['status'] ?? null) === 'inactive', fn ($q) => $q->where('active', false))
            ->when(($filters['status'] ?? null) === 'low_stock', fn ($q) => $q->lowStock())
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('products/index', [
            'products' => $products,
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('products/form', [
            'product' => null,
            'categories' => Category::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(ProductRequest $request, StockService $stock): RedirectResponse
    {
        $data = $request->validated();
        $initialStock = (float) ($data['stock'] ?? 0);
        unset($data['stock']);

        DB::transaction(function () use ($data, $initialStock, $request, $stock) {
            $data['code'] = ($data['code'] ?? null) ?: $this->nextCode();
            $product = Product::create($data + ['stock' => 0]);
            if ($product->track_stock && $initialStock > 0) {
                $stock->move($product, $initialStock, StockMovementType::Adjustment, $request->user(), null, 'Stock inicial');
            }
        });

        return to_route('products.index')->with('success', 'Producto creado.');
    }

    public function edit(Product $product): Response
    {
        return Inertia::render('products/form', [
            'product' => $product,
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'movements' => $product->stockMovements()->with('user:id,name')->latest('id')->limit(15)->get(),
        ]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $data = $request->validated();
        $data['code'] ??= $product->code;
        $product->update($data);

        return to_route('products.index')->with('success', 'Producto actualizado.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        // Con ventas: se elimina lógicamente (soft delete) para conservar el historial.
        $product->update(['active' => false]);
        $product->delete();

        return to_route('products.index')->with('success', 'Producto eliminado.');
    }

    public function adjustStock(Request $request, Product $product, StockService $stock): RedirectResponse
    {
        $data = $request->validate([
            'stock' => ['required', 'numeric', 'min:0', 'max:999999'],
            'note' => ['required', 'string', 'max:255'],
        ], [], ['stock' => 'stock contado', 'note' => 'motivo']);

        if (! $product->track_stock) {
            return back()->with('error', 'Este producto no controla stock.');
        }

        $stock->adjustTo($product, (float) $data['stock'], $request->user(), $data['note']);

        return back()->with('success', 'Stock actualizado.');
    }

    private function nextCode(): string
    {
        $last = Product::withTrashed()->where('code', 'like', 'P%')->orderByDesc('id')->value('code');
        $n = $last && preg_match('/^P(\d+)$/', $last, $m) ? (int) $m[1] + 1 : Product::withTrashed()->count() + 1;

        do {
            $code = 'P'.str_pad((string) $n++, 4, '0', STR_PAD_LEFT);
        } while (Product::withTrashed()->where('code', $code)->exists());

        return $code;
    }
}
