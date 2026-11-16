<?php

use App\Enums\DocumentStatus;
use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\DocumentVoiding;
use App\Models\ElectronicDocument;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

const API2 = 'https://api.test/v1';

function apiDoc(int $id, int $number, string $status, string $series): array
{
    return ['estado' => 'exito', 'mensaje' => 'OK', 'datos' => [
        'id' => $id, 'serie' => $series, 'correlativo' => $number,
        'sunat' => ['estado' => $status, 'codigo' => $status === 'aceptado' ? '0' : null, 'descripcion' => null, 'notas' => [], 'hash_cpe' => null],
    ]];
}

/** Venta con comprobante aceptado: 2 x Pote (S/ 15) + 1 x Paleta (S/ 4) = S/ 34. */
function acceptedSale(string $type = 'boleta', ?int $customerId = null): Sale
{
    $endpoint = $type === 'factura' ? '/facturas' : '/boletas';
    Http::fake([API2.$endpoint => Http::response(apiDoc(500, 10, 'aceptado', $type === 'factura' ? 'F001' : 'B001'), 201)]);

    test()->actingAs(test()->admin)->post('/pos', [
        'items' => [
            ['product_id' => test()->pote->id, 'quantity' => 2],
            ['product_id' => test()->paleta->id, 'quantity' => 1],
        ],
        'payment_method' => 'efectivo',
        'document_type' => $type,
        'customer_id' => $customerId,
    ])->assertSessionHasNoErrors();

    return Sale::latest('id')->first();
}

beforeEach(function () {
    Settings::set(['api_base_url' => API2, 'api_key' => 'k', 'api_secret' => 's']);
    $this->admin = User::factory()->admin()->create();
    $this->cashier = User::factory()->create();
    $this->pote = Product::factory()->withStock(10)->create(['name' => 'Pote 1/2 litro', 'price' => 15]);
    $this->paleta = Product::factory()->withStock(10)->create(['name' => 'Paleta', 'price' => 4]);
});

test('partial return credit note sends selected items and restocks', function () {
    $sale = acceptedSale();
    $doc = $sale->invoice;
    $poteItem = $sale->items->firstWhere('product_id', $this->pote->id);
    Http::fake([API2.'/notas-credito' => Http::response(apiDoc(70, 1, 'aceptado', 'BC01'), 201)]);

    $this->actingAs($this->admin)->post("/documents/{$doc->id}/credit-note", [
        'code' => '07', 'reason' => 'Cliente devolvió un pote', 'quantities' => [$poteItem->id => 1], 'restock' => true,
    ])->assertSessionHas('success');

    $note = ElectronicDocument::where('type', '07')->sole();
    expect((float) $note->total)->toBe(15.0)
        ->and($note->reason_code)->toBe('07')
        ->and($doc->fresh()->status)->toBe(DocumentStatus::Aceptado)
        ->and((float) $this->pote->fresh()->stock)->toBe(9.0) // 10 - 2 + 1
        ->and($sale->fresh()->status)->toBe(SaleStatus::Completada);

    Http::assertSent(fn (Request $r) => $r->url() === API2.'/notas-credito'
        && $r['cod_motivo'] === '07'
        && count($r['items']) === 1
        && $r['items'][0]['cantidad'] == 1
        && $r['items'][0]['precio_unitario'] == 15);
});

test('full annulment credit note voids the document and optionally the sale', function () {
    $sale = acceptedSale();
    Http::fake([API2.'/notas-credito' => Http::response(apiDoc(71, 2, 'pendiente', 'BC01'), 201)]);

    $this->actingAs($this->admin)->post("/documents/{$sale->invoice->id}/credit-note", [
        'code' => '01', 'reason' => 'Anulación de la operación', 'void_sale' => true,
    ])->assertSessionHas('success');

    expect(ElectronicDocument::where('type', '03')->sole()->status)->toBe(DocumentStatus::Anulado)
        ->and($sale->fresh()->status)->toBe(SaleStatus::Anulada)
        ->and((float) $this->pote->fresh()->stock)->toBe(10.0);
});

test('credit notes cannot exceed the remaining balance', function () {
    $sale = acceptedSale();
    Http::fake([API2.'/notas-credito' => Http::response(apiDoc(72, 3, 'aceptado', 'BC01'), 201)]);

    $this->actingAs($this->admin)->post("/documents/{$sale->invoice->id}/credit-note", ['code' => '04', 'reason' => 'Descuento', 'amount' => 30])
        ->assertSessionHas('success');
    $this->actingAs($this->admin)->post("/documents/{$sale->invoice->id}/credit-note", ['code' => '04', 'reason' => 'Otro descuento', 'amount' => 5])
        ->assertSessionHasErrors('amount');
});

test('debit note uses the BD01 series and the chosen reason', function () {
    $sale = acceptedSale();
    Http::fake([API2.'/notas-debito' => Http::response(apiDoc(80, 1, 'enviado', 'BD01'), 201)]);

    $this->actingAs($this->admin)->post("/documents/{$sale->invoice->id}/debit-note", [
        'code' => '03', 'reason' => 'Recargo por delivery', 'amount' => 5,
    ])->assertSessionHas('success');

    $note = ElectronicDocument::where('type', '08')->sole();
    expect($note->series)->toBe('BD01')->and((float) $note->total)->toBe(5.0);
    Http::assertSent(fn (Request $r) => $r->url() === API2.'/notas-debito'
        && $r['serie'] === 'BD01' && $r['cod_motivo'] === '03' && $r['doc_afectado_tipo'] === '03');
});

test('factura is voided with a communication (RA) and becomes annulled when SUNAT accepts', function () {
    $company = Customer::factory()->company()->create();
    $sale = acceptedSale('factura', $company->id);
    $doc = $sale->invoice;
    Http::fake([
        API2.'/anulaciones' => Http::response(['estado' => 'exito', 'datos' => [
            'id' => 51, 'identifier' => 'RA-20261116-001', 'ticket' => '179459', 'sunat_status' => 'enviado',
        ]], 201),
        API2.'/anulaciones/51/estado' => Http::response(['estado' => 'exito', 'datos' => [
            'id_anulacion' => 51, 'identifier' => 'RA-20261116-001', 'estado_sunat' => 'aceptado', 'codigo_sunat' => '0',
            'descripcion_sunat' => 'La Comunicacion de baja RA-20261116-001, ha sido aceptada',
        ]]),
    ]);

    $this->actingAs($this->admin)->post("/documents/{$doc->id}/voiding", ['reason' => 'Error en datos del cliente', 'void_sale' => true])
        ->assertSessionHas('success');

    $voiding = DocumentVoiding::sole();
    expect($voiding->kind)->toBe('RA')
        ->and($voiding->status)->toBe(DocumentStatus::Enviado)
        ->and($doc->fresh()->status)->toBe(DocumentStatus::Aceptado)
        ->and($sale->fresh()->status)->toBe(SaleStatus::Anulada);
    Http::assertSent(fn (Request $r) => $r->url() === API2.'/anulaciones'
        && $r['detalles'][0] == ['tipo_documento' => '01', 'serie' => 'F001', 'correlativo' => '10', 'motivo' => 'Error en datos del cliente']);

    $this->artisan('facturacion:sincronizar')->assertSuccessful();

    expect($voiding->fresh()->status)->toBe(DocumentStatus::Aceptado)
        ->and($doc->fresh()->status)->toBe(DocumentStatus::Anulado);

    // No se puede pedir otra baja.
    $this->actingAs($this->admin)->post("/documents/{$doc->id}/voiding", ['reason' => 'Otra vez'])->assertSessionHasErrors('document');
});

test('boleta is voided with a summary (RC) using the provider id', function () {
    $sale = acceptedSale();
    Http::fake([API2.'/resumenes' => Http::response(['estado' => 'exito', 'datos' => [
        'id' => 58, 'identifier' => 'RC-20261116-001', 'ticket' => '1793', 'estado_sunat' => 'enviado',
    ]], 201)]);

    $this->actingAs($this->admin)->post("/documents/{$sale->invoice->id}/voiding", ['reason' => 'Cliente solicita anulación'])
        ->assertSessionHas('success');

    expect(DocumentVoiding::sole()->kind)->toBe('RC');
    Http::assertSent(fn (Request $r) => $r->url() === API2.'/resumenes'
        && $r['anular'] == [['id' => 500, 'motivo' => 'Cliente solicita anulación']]
        && $r['fecha_resumen'] === now()->toDateString());
});

test('voiding is not allowed after 7 days', function () {
    $sale = acceptedSale();
    $sale->invoice->update(['issued_at' => now()->subDays(9)]);

    $this->actingAs($this->admin)->post("/documents/{$sale->invoice->id}/voiding", ['reason' => 'Tarde'])
        ->assertSessionHasErrors(['document' => 'Fuera de plazo (7 días): use nota de crédito']);
});

test('cashier cannot issue notes or voidings', function () {
    $sale = acceptedSale();

    $this->actingAs($this->cashier)->post("/documents/{$sale->invoice->id}/credit-note", ['code' => '01', 'reason' => 'x'])->assertForbidden();
    $this->actingAs($this->cashier)->post("/documents/{$sale->invoice->id}/debit-note", ['code' => '01', 'reason' => 'x', 'amount' => 1])->assertForbidden();
    $this->actingAs($this->cashier)->post("/documents/{$sale->invoice->id}/voiding", ['reason' => 'x'])->assertForbidden();
});

test('documents index exposes available actions per row', function () {
    $sale = acceptedSale();

    $this->actingAs($this->admin)->get('/documents')
        ->assertInertia(fn (Assert $p) => $p->component('documents/index')
            ->where('documents.data.0.actions.credit_note', true)
            ->where('documents.data.0.actions.void', true)
            ->where('pdfFormat', 'ticket-80'));

    $this->actingAs($this->admin)->getJson("/documents/{$sale->invoice->id}/note-data")
        ->assertOk()
        ->assertJsonPath('remaining', 34)
        ->assertJsonPath('void_kind', 'RC')
        ->assertJsonCount(2, 'items');
});
