<?php

use App\Enums\DocumentStatus;
use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\ElectronicDocument;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * Las respuestas simuladas siguen el formato real del API verificado en beta:
 * {estado, mensaje, datos: {id, serie, correlativo, sunat: {estado, codigo, descripcion, hash_cpe}}}
 * porque la colección de Postman no incluye respuestas de ejemplo. Al obtener respuestas
 * reales en beta, reemplazarlas aquí y ajustar ResponseMapper.
 */

const API = 'https://api.test/v1';

beforeEach(function () {
    Settings::set([
        'api_base_url' => API,
        'api_key' => 'key-123',
        'api_secret' => 'secret-456',
        'company_ruc' => '20100000001',
    ]);
    $this->cashier = User::factory()->create();
    $this->admin = User::factory()->admin()->create();
    $this->product = Product::factory()->create(['code' => 'P0001', 'name' => 'Helado 2 bolas', 'price' => 7.00]);
});

function sellWith(string $type, ?int $customerId = null, float $qty = 2): Sale
{
    test()->actingAs(test()->cashier)->post('/pos', [
        'items' => [['product_id' => test()->product->id, 'quantity' => $qty]],
        'payment_method' => 'yape',
        'document_type' => $type,
        'customer_id' => $customerId,
    ])->assertSessionHasNoErrors();

    return Sale::latest('id')->first();
}

function providerDoc(int $id, int $number, string $status = 'pendiente', string $series = 'B001'): array
{
    return ['estado' => 'exito', 'mensaje' => 'OK', 'datos' => [
        'id' => $id, 'serie' => $series, 'correlativo' => $number,
        'numero_completo' => sprintf('%s-%06d', $series, $number),
        'sunat' => ['estado' => $status, 'codigo' => $status === 'aceptado' ? '0' : null, 'descripcion' => null, 'notas' => [], 'hash_cpe' => null],
    ]];
}

test('a boleta to final consumer is sent with the collection payload and credentials', function () {
    Http::fake([API.'/boletas' => Http::response(providerDoc(15, 101), 201)]);

    $sale = sellWith('boleta');
    $doc = $sale->documents()->sole();

    expect($doc->provider_id)->toBe('15')
        ->and($doc->number)->toBe(101)
        ->and($doc->full_number)->toBe('B001-00000101')
        ->and($doc->status)->toBe(DocumentStatus::Pendiente);

    Http::assertSent(function (Request $request) {
        $body = $request->data();

        return $request->url() === API.'/boletas'
            && $request->method() === 'POST'
            && $request->header('X-Api-Key')[0] === 'key-123'
            && $request->header('X-Api-Secret')[0] === 'secret-456'
            && $body['serie'] === 'B001'
            && $body['cliente'] === ['tipo_doc' => '0', 'num_doc' => '99999999', 'razon_social' => 'CONSUMIDOR FINAL']
            && $body['items'][0] == [
                'codigo' => 'P0001', 'descripcion' => 'Helado 2 bolas', 'unidad' => 'NIU',
                'cantidad' => 2.0, 'precio_unitario' => 7.0, 'tip_afe_igv' => '10',
            ]
            && ! isset($body['correlativo']);
    });
});

test('a factura requires a RUC customer and sends tipo_operacion 0101', function () {
    Http::fake([API.'/facturas' => Http::response(providerDoc(7, 55, 'aceptado', 'F001'), 201)]);
    $person = Customer::factory()->create();
    $company = Customer::factory()->company()->create();

    $this->actingAs($this->cashier)->post('/pos', [
        'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        'payment_method' => 'tarjeta', 'document_type' => 'factura', 'customer_id' => $person->id,
    ])->assertSessionHasErrors('customer_id');

    $sale = sellWith('factura', $company->id);

    expect($sale->documents()->sole()->status)->toBe(DocumentStatus::Aceptado);
    Http::assertSent(fn (Request $r) => $r->url() === API.'/facturas'
        && $r['tipo_operacion'] === '0101'
        && $r['cliente']['tipo_doc'] === '6'
        && $r['cliente']['direccion'] === $company->address);
});

test('the sale is kept when the provider is down and the sync command retries it', function () {
    Http::fake([API.'/boletas*' => Http::sequence()
        ->pushFailedConnection()                                                         // 1. POST: sin conexión
        ->push(['estado' => 'exito', 'datos' => ['datos' => [], 'paginacion' => []]])   // 2. GET: no existe en el proveedor
        ->push(providerDoc(20, 102), 201)                                                // 3. POST: emitido
        ->push(providerDoc(20, 102))]);                                                  // 4. GET /boletas/20: estado

    $sale = sellWith('boleta');
    $doc = $sale->documents()->sole();

    expect($sale->status)->toBe(SaleStatus::Completada)
        ->and($doc->status)->toBe(DocumentStatus::ErrorEnvio)
        ->and($doc->attempts)->toBe(1);

    // Aún dentro del backoff: no reintenta.
    $this->artisan('facturacion:sincronizar')->assertSuccessful();
    expect($doc->fresh()->status)->toBe(DocumentStatus::ErrorEnvio);

    $this->travel(2)->minutes();
    $this->artisan('facturacion:sincronizar')->assertSuccessful();

    expect($doc->fresh())
        ->status->toBe(DocumentStatus::Pendiente)
        ->provider_id->toBe('20')
        ->attempts->toBe(2);
});

test('after max attempts the document goes to manual review', function () {
    config(['facturacion.max_attempts' => 1]);
    Http::fake([API.'/boletas' => Http::response(['message' => 'Server error'], 503)]);

    $doc = sellWith('boleta')->documents()->sole();

    expect($doc->status)->toBe(DocumentStatus::RevisionManual);
});

test('provider validation errors are stored and not retried', function () {
    Http::fake([API.'/boletas' => Http::response(['message' => 'Datos inválidos', 'errors' => ['cliente.num_doc' => ['DNI inválido']]], 422)]);

    $doc = sellWith('boleta')->documents()->sole();

    expect($doc->status)->toBe(DocumentStatus::Error)
        ->and($doc->sunat_message)->toContain('DNI inválido');
});

test('invalid credentials are reported as an error', function () {
    Http::fake([API.'/boletas' => Http::response(['message' => 'Unauthenticated'], 401)]);

    $doc = sellWith('boleta')->documents()->sole();

    expect($doc->status)->toBe(DocumentStatus::Error)
        ->and($doc->sunat_message)->toContain('Credenciales');
});

test('the sync command refreshes pending documents', function () {
    Http::fake([
        API.'/boletas' => Http::response(providerDoc(30, 103), 201),
        API.'/boletas/30' => Http::response(['estado' => 'exito', 'mensaje' => 'OK', 'datos' => ['id' => 30, 'serie' => 'B001', 'correlativo' => 103, 'sunat' => ['estado' => 'aceptado', 'codigo' => '0', 'descripcion' => 'La Boleta numero B001-103, ha sido aceptada', 'notas' => [], 'hash_cpe' => 'e2nVaEfhOz7FmJu5NeUsZEDfwNs=']]]),
    ]);

    $doc = sellWith('boleta')->documents()->sole();
    $this->artisan('facturacion:sincronizar')->assertSuccessful();

    expect($doc->fresh())
        ->status->toBe(DocumentStatus::Aceptado)
        ->sunat_code->toBe('0')
        ->last_checked_at->not->toBeNull();
});

test('voiding a sale with an accepted boleta issues a credit note', function () {
    Http::fake([
        API.'/boletas' => Http::response(providerDoc(40, 104, 'aceptado'), 201),
        API.'/notas-credito' => Http::response(providerDoc(5, 1, 'pendiente', 'BC01'), 201),
    ]);

    $sale = sellWith('boleta');
    $boleta = $sale->documents()->sole();

    $this->actingAs($this->admin)
        ->post("/sales/{$sale->id}/void", ['reason' => 'Anulación de la operación'])
        ->assertSessionHas('success');

    $nc = ElectronicDocument::where('type', '07')->sole();
    expect($nc->related_document_id)->toBe($boleta->id)
        ->and($nc->series)->toBe('BC01')
        ->and($boleta->fresh()->status)->toBe(DocumentStatus::Anulado)
        ->and($sale->fresh()->status)->toBe(SaleStatus::Anulada);

    Http::assertSent(fn (Request $r) => $r->url() === API.'/notas-credito'
        && $r['doc_afectado_tipo'] === '03'
        && $r['doc_afectado_serie'] === 'B001'
        && $r['doc_afectado_correlativo'] === '104'
        && $r['cod_motivo'] === '01');
});

test('the sale is not voided if the credit note fails', function () {
    Http::fake([
        API.'/boletas' => Http::response(providerDoc(41, 105, 'aceptado'), 201),
        API.'/notas-credito' => Http::response(['message' => 'Serie BC01 no existe'], 422),
    ]);

    $sale = sellWith('boleta');

    $this->actingAs($this->admin)
        ->post("/sales/{$sale->id}/void", ['reason' => 'Error'])
        ->assertSessionHas('error');

    expect($sale->fresh()->status)->toBe(SaleStatus::Completada)
        ->and(ElectronicDocument::where('type', '07')->count())->toBe(0)
        ->and($sale->documents()->sole()->status)->toBe(DocumentStatus::Aceptado);
});

test('voiding a sale with a rejected boleta discards it in the provider', function () {
    Http::fake([
        API.'/boletas' => Http::response(providerDoc(42, 106, 'rechazado'), 201),
        API.'/boletas/42' => Http::response(['message' => 'Eliminado']),
    ]);

    $sale = sellWith('boleta');
    $this->actingAs($this->admin)->post("/sales/{$sale->id}/void", ['reason' => 'Error'])->assertSessionHas('success');

    expect($sale->documents()->sole()->status)->toBe(DocumentStatus::Anulado);
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === API.'/boletas/42');
});

test('a rejected document can be corrected with PUT', function () {
    Http::fake([
        API.'/boletas' => Http::response(providerDoc(43, 107, 'rechazado'), 201),
        API.'/boletas/43' => Http::response(providerDoc(43, 107, 'enviado')),
    ]);

    $doc = sellWith('boleta')->documents()->sole();

    $this->actingAs($this->cashier)->put("/documents/{$doc->id}", [
        'tipo_doc' => '1', 'num_doc' => '72243561', 'razon_social' => 'CHAVEZ HUINCHO JORGE',
    ])->assertSessionHas('success');

    expect($doc->fresh()->status)->toBe(DocumentStatus::Enviado)
        ->and($doc->fresh()->customer_snapshot['num_doc'])->toBe('72243561');
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['cliente']['num_doc'] === '72243561');
});

test('pdf download is proxied without exposing credentials', function () {
    Http::fake([
        API.'/boletas' => Http::response(providerDoc(44, 108), 201),
        API.'/boletas/44/pdf*' => Http::response('%PDF-1.4 fake', 200, ['Content-Type' => 'application/pdf']),
    ]);

    $doc = sellWith('boleta')->documents()->sole();

    $this->actingAs($this->cashier)
        ->get("/documents/{$doc->id}/download/pdf")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/boletas/44/pdf?format=ticket-80'));
});

test('a sale without document can get a boleta later', function () {
    Http::fake([API.'/boletas' => Http::response(providerDoc(45, 109), 201)]);

    $sale = sellWith('ninguno');
    $this->actingAs($this->cashier)
        ->post("/sales/{$sale->id}/documents", ['document_type' => 'boleta'])
        ->assertRedirect();

    expect($sale->fresh()->invoice->full_number)->toBe('B001-00000109');
});

test('api secret is stored encrypted', function () {
    $raw = Setting::where('key', 'api_secret')->value('value');

    expect($raw)->not->toBe('secret-456')
        ->and(Settings::get('api_secret'))->toBe('secret-456');
});

test('connection test stores company regime', function () {
    Http::fake([API.'/empresa' => Http::response(['estado' => 'exito', 'mensaje' => 'OK', 'datos' => [
        'ruc' => '20100000001', 'razon_social' => 'HELADERIA SAC', 'tax_regime' => 'rus',
        'tasa_igv_vigente' => 0, 'entorno' => 'beta',
    ]])]);

    $this->actingAs($this->admin)->post('/settings/billing/test')->assertSessionHas('success');

    expect(Settings::get('tax_regime'))->toBe('rus')
        ->and(Settings::canIssueFactura())->toBeFalse();
});

test('saving credentials connects and fixes missing series automatically', function () {
    Settings::set(['series_boleta' => 'B999']);
    Http::fake([
        API.'/empresa' => Http::response(['estado' => 'exito', 'mensaje' => 'OK', 'datos' => [
            'ruc' => '20161515648', 'razon_social' => 'MI EMPRESA SAC', 'tax_regime' => 'mype', 'tasa_igv_vigente' => 18, 'entorno' => 'beta',
        ]]),
        API.'/series' => Http::response(['estado' => 'exito', 'mensaje' => 'OK', 'datos' => [
            ['id' => 48, 'tipo' => 'boleta', 'serie' => 'B001', 'activo' => true],
            ['id' => 47, 'tipo' => 'factura', 'serie' => 'F001', 'activo' => true],
            ['id' => 50, 'tipo' => 'nota_credito', 'serie' => 'BC01', 'activo' => true],
            ['id' => 51, 'tipo' => 'nota_credito', 'serie' => 'FC01', 'activo' => true],
            ['id' => 52, 'tipo' => 'nota_debito', 'serie' => 'BD01', 'activo' => true],
            ['id' => 53, 'tipo' => 'nota_debito', 'serie' => 'FD01', 'activo' => true],
        ]]),
    ]);

    $this->actingAs($this->admin)->put('/settings/billing', [
        'api_base_url' => API, 'api_key' => 'key-123', 'api_secret' => '',
        'series_boleta' => 'B999', 'series_factura' => 'F001', 'series_nc_boleta' => 'BC01', 'series_nc_factura' => 'FC01', 'series_nd_boleta' => 'BD01', 'series_nd_factura' => 'FD01',
        'pdf_format' => 'ticket-80',
    ])->assertSessionHas('success')->assertSessionMissing('error');

    expect(Settings::get('series_boleta'))->toBe('B001')
        ->and(Settings::get('company_name'))->toBe('MI EMPRESA SAC')
        ->and(Settings::get('environment'))->toBe('beta');
});

test('an HTTP 200 response with estado error is treated as an error', function () {
    Http::fake([API.'/boletas' => Http::response(['estado' => 'error', 'mensaje' => 'La serie B001 no pertenece a la empresa'], 200)]);

    $doc = sellWith('boleta')->documents()->sole();

    expect($doc->status)->toBe(DocumentStatus::Error)
        ->and($doc->sunat_message)->toContain('no pertenece');
});

test('a retry links the document already created in the provider instead of duplicating it', function () {
    Http::fake([API.'/boletas*' => Http::sequence()
        ->pushFailedConnection() // el POST llegó al proveedor, pero la respuesta se perdió
        ->push(['estado' => 'exito', 'datos' => ['datos' => [[
            'id' => 651, 'serie' => 'B001', 'correlativo' => 336, 'fecha_emision' => now()->format('Y-m-d H:i:s'),
            'cliente' => ['tipo_doc' => '0', 'num_doc' => '99999999', 'razon_social' => 'CONSUMIDOR FINAL'],
            'totales' => ['total' => 14.0],
            'sunat' => ['estado' => 'aceptado', 'codigo' => '0', 'descripcion' => 'Aceptada', 'notas' => [], 'hash_cpe' => 'abc'],
        ]], 'paginacion' => []]])]);

    $doc = sellWith('boleta')->documents()->sole();
    expect($doc->status)->toBe(DocumentStatus::ErrorEnvio);

    $this->travel(2)->minutes();
    $this->artisan('facturacion:sincronizar')->assertSuccessful();

    expect($doc->fresh())
        ->provider_id->toBe('651')
        ->number->toBe(336)
        ->status->toBe(DocumentStatus::Aceptado);
    Http::assertSentCount(2); // un POST y un GET; ningún POST adicional
});

test('status endpoint refreshes a pending document for the live viewer', function () {
    Http::fake([
        API.'/boletas' => Http::response(providerDoc(60, 120, 'enviado'), 201),
        API.'/boletas/60' => Http::response(providerDoc(60, 120, 'aceptado')),
    ]);
    $doc = sellWith('boleta')->documents()->sole();

    $this->actingAs($this->cashier)->getJson("/documents/{$doc->id}/status")
        ->assertOk()
        ->assertJson(['status' => 'aceptado', 'registered' => true, 'final' => true, 'number' => 'B001-00000120']);

    // Estado definitivo: no vuelve a consultar al proveedor.
    $this->actingAs($this->cashier)->getJson("/documents/{$doc->id}/status")->assertJson(['status' => 'aceptado']);
    Http::assertSentCount(2);
});

test('pdf can be forced to download and errors render inside the viewer', function () {
    Http::fake([
        API.'/boletas' => Http::response(providerDoc(61, 121), 201),
        API.'/boletas/61/pdf*' => Http::response('%PDF-1.7', 200, ['Content-Type' => 'application/pdf']),
        API.'/boletas/61/xml' => Http::response(['estado' => 'error', 'mensaje' => 'XML no generado'], 404),
    ]);
    $doc = sellWith('boleta')->documents()->sole();

    $this->actingAs($this->cashier)->get("/documents/{$doc->id}/download/pdf?format=a4&download=1")
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="B001-00000121-a4.pdf"');

    $this->actingAs($this->cashier)->get("/documents/{$doc->id}/download/xml")
        ->assertStatus(502)
        ->assertSee('XML no generado');
});
