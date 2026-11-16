<?php

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\SaleService;
use Inertia\Testing\AssertableInertia as Assert;

test('every admin page renders', function () {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->withStock(10, 2)->create();
    Customer::factory()->company()->create();
    $sale = app(SaleService::class)->create([
        'items' => [['product_id' => $product->id, 'quantity' => 2]],
        'payment_method' => 'efectivo',
        'document_type' => 'ninguno',
    ], $admin);

    $pages = [
        '/pos' => 'pos/index',
        '/dashboard' => 'dashboard',
        '/sales' => 'sales/index',
        "/sales/{$sale->id}" => 'sales/show',
        "/sales/{$sale->id}/ticket" => 'sales/ticket',
        '/products' => 'products/index',
        '/products?status=low_stock' => 'products/index',
        '/products/create' => 'products/form',
        "/products/{$product->id}/edit" => 'products/form',
        '/categories' => 'categories/index',
        '/customers' => 'customers/index',
        '/documents' => 'documents/index',
        '/reports' => 'reports/index',
        '/settings/business' => 'settings/business',
        '/settings/billing' => 'settings/billing',
        '/settings/users' => 'settings/users',
    ];

    foreach ($pages as $url => $component) {
        $this->actingAs($admin)->get($url)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component($component));
    }
});

test('admin manages products and categories', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $this->post('/categories', ['name' => 'Helados', 'active' => true])->assertSessionHasNoErrors();
    $category = Category::sole();

    $this->post('/products', [
        'category_id' => $category->id, 'name' => 'Pote 1 litro', 'price' => '28.00',
        'track_stock' => true, 'stock' => 12, 'stock_min' => 3, 'active' => true,
    ])->assertRedirect('/products');

    $product = Product::sole();
    expect($product->code)->toBe('P0001')
        ->and((float) $product->stock)->toBe(12.0);

    $this->post("/products/{$product->id}/stock", ['stock' => 20, 'note' => 'Ingreso'])->assertSessionHas('success');
    expect((float) $product->fresh()->stock)->toBe(20.0);

    $this->delete("/categories/{$category->id}")->assertSessionHas('error');
    $this->delete("/products/{$product->id}")->assertRedirect('/products');
    expect(Product::count())->toBe(0)->and(Product::withTrashed()->count())->toBe(1);
});

test('cashier cannot see sales from other days', function () {
    $cashier = User::factory()->create();
    $product = Product::factory()->create();
    $sale = app(SaleService::class)->create([
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'payment_method' => 'yape',
        'document_type' => 'ninguno',
    ], $cashier);
    Sale::whereKey($sale->id)->update(['sold_at' => now()->subDays(2)]);

    $this->actingAs($cashier)->get("/sales/{$sale->id}")->assertForbidden();
});
