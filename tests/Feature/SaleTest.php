<?php

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\ReportService;
use App\Services\SaleService;

beforeEach(function () {
    $this->cashier = User::factory()->create();
    $this->admin = User::factory()->admin()->create();
});

function saleData(array $items, array $overrides = []): array
{
    return array_merge([
        'items' => $items,
        'payment_method' => 'efectivo',
        'amount_received' => null,
        'document_type' => 'ninguno',
        'customer_id' => null,
    ], $overrides);
}

test('cashier registers a cash sale without document', function () {
    $cone = Product::factory()->create(['price' => 5.00]);
    $pote = Product::factory()->withStock(10)->create(['price' => 15.00]);

    $this->actingAs($this->cashier)
        ->post('/pos', saleData([
            ['product_id' => $cone->id, 'quantity' => 2],
            ['product_id' => $pote->id, 'quantity' => 1],
        ], ['amount_received' => 50]))
        ->assertRedirect('/pos')
        ->assertSessionHas('last_sale');

    $sale = Sale::with('items')->sole();
    expect((float) $sale->total)->toBe(25.0)
        ->and((float) $sale->change)->toBe(25.0)
        ->and($sale->items)->toHaveCount(2)
        ->and($sale->documents()->count())->toBe(0)
        ->and((float) $pote->fresh()->stock)->toBe(9.0)
        ->and((float) $cone->fresh()->stock)->toBe(0.0); // no controla stock

    $this->assertDatabaseHas('stock_movements', ['product_id' => $pote->id, 'type' => 'sale', 'quantity' => -1, 'sale_id' => $sale->id]);
});

test('prices always come from the database', function () {
    $product = Product::factory()->create(['price' => 8.00]);

    $this->actingAs($this->cashier)->post('/pos', saleData([
        ['product_id' => $product->id, 'quantity' => 1, 'price' => 0.01],
    ], ['payment_method' => 'yape']));

    expect((float) Sale::sole()->total)->toBe(8.0)
        ->and(Sale::sole()->amount_received)->toBeNull();
});

test('duplicate lines of the same product are merged', function () {
    $product = Product::factory()->withStock(5)->create(['price' => 4]);

    $this->actingAs($this->cashier)->post('/pos', saleData([
        ['product_id' => $product->id, 'quantity' => 1],
        ['product_id' => $product->id, 'quantity' => 2],
    ]));

    $sale = Sale::with('items')->sole();
    expect($sale->items)->toHaveCount(1)
        ->and((float) $sale->items[0]->quantity)->toBe(3.0)
        ->and((float) $product->fresh()->stock)->toBe(2.0);
});

test('sale is rejected when stock is insufficient', function () {
    $product = Product::factory()->withStock(1)->create();

    $this->actingAs($this->cashier)
        ->post('/pos', saleData([['product_id' => $product->id, 'quantity' => 2]]))
        ->assertSessionHasErrors('items');

    expect(Sale::count())->toBe(0)->and((float) $product->fresh()->stock)->toBe(1.0);
});

test('cash received cannot be lower than total', function () {
    $product = Product::factory()->create(['price' => 10]);

    $this->actingAs($this->cashier)
        ->post('/pos', saleData([['product_id' => $product->id, 'quantity' => 1]], ['amount_received' => 5]))
        ->assertSessionHasErrors('amount_received');
});

test('inactive products cannot be sold', function () {
    $product = Product::factory()->create(['active' => false]);

    $this->actingAs($this->cashier)
        ->post('/pos', saleData([['product_id' => $product->id, 'quantity' => 1]]))
        ->assertSessionHasErrors('items');
});

test('electronic documents require billing configuration', function () {
    $product = Product::factory()->create();

    $this->actingAs($this->cashier)
        ->post('/pos', saleData([['product_id' => $product->id, 'quantity' => 1]], ['document_type' => 'boleta']))
        ->assertSessionHasErrors('document_type');
});

test('admin voids a sale and stock is restored', function () {
    $product = Product::factory()->withStock(10)->create();
    $this->actingAs($this->cashier)->post('/pos', saleData([['product_id' => $product->id, 'quantity' => 3]]));
    $sale = Sale::sole();

    $this->actingAs($this->cashier)->post("/sales/{$sale->id}/void", ['reason' => 'Error'])->assertForbidden();

    $this->actingAs($this->admin)
        ->post("/sales/{$sale->id}/void", ['reason' => 'Cliente se arrepintió'])
        ->assertSessionHas('success');

    expect($sale->fresh()->status)->toBe(SaleStatus::Anulada)
        ->and((float) $product->fresh()->stock)->toBe(10.0);
});

test('voided sales are excluded from reports', function () {
    $product = Product::factory()->create(['price' => 10]);
    $this->actingAs($this->cashier)->post('/pos', saleData([['product_id' => $product->id, 'quantity' => 1]]));
    $this->actingAs($this->cashier)->post('/pos', saleData([['product_id' => $product->id, 'quantity' => 2]]));
    app(SaleService::class)->void(Sale::first(), $this->admin, 'x');

    $summary = app(ReportService::class)->summary(today(), today());

    expect($summary['count'])->toBe(1)->and($summary['total'])->toBe(20.0);
});

test('customer search returns json for the POS', function () {
    Customer::factory()->create(['doc_number' => '45678912', 'name' => 'ANA TORRES']);

    $this->actingAs($this->cashier)
        ->getJson('/customers/search?q=4567')
        ->assertOk()
        ->assertJsonPath('0.name', 'ANA TORRES');
});

test('customer validation enforces DNI and RUC formats', function () {
    $this->actingAs($this->cashier)
        ->postJson('/customers/quick', ['doc_type' => '1', 'doc_number' => '123', 'name' => 'X'])
        ->assertJsonValidationErrors('doc_number');

    $this->actingAs($this->cashier)
        ->postJson('/customers/quick', ['doc_type' => '6', 'doc_number' => '20601234567', 'name' => 'EMPRESA SAC'])
        ->assertJsonValidationErrors('address');

    $this->actingAs($this->cashier)
        ->postJson('/customers/quick', ['doc_type' => '1', 'doc_number' => '12345678', 'name' => 'juan perez'])
        ->assertCreated()
        ->assertJsonPath('name', 'JUAN PEREZ');
});
