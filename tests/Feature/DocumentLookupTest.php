<?php

use App\Models\Customer;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

const LOOKUP = 'https://api.json.pe/api';

/** Respuestas reales de ejemplo de api.json.pe. */
function dniResponse(): array
{
    return ['success' => true, 'message' => 'exito', 'data' => [
        'numero' => '27427864', 'nombre_completo' => 'CASTILLO TERRONES, JOSE PEDRO', 'nombres' => 'JOSE PEDRO',
        'apellido_paterno' => 'CASTILLO', 'apellido_materno' => 'TERRONES', 'codigo_verificacion' => 7,
        'direccion' => '', 'direccion_completa' => '', 'ubigeo_reniec' => '', 'ubigeo_sunat' => '', 'ubigeo' => [null, null, null],
    ]];
}

function rucResponse(string $estado = 'ACTIVO'): array
{
    return ['success' => true, 'message' => 'exito', 'data' => [
        'ruc' => '20552103816', 'nombre_o_razon_social' => 'AGROLIGHT PERU S.A.C.', 'estado' => $estado, 'condicion' => 'HABIDO',
        'departamento' => 'LIMA', 'provincia' => 'LIMA', 'distrito' => 'SANTA ANITA',
        'direccion' => 'PJ. JORGE BASADRE NRO. 158 URB. POP LA UNIVERSAL 2DA ET.',
        'direccion_completa' => 'PJ. JORGE BASADRE NRO. 158 URB. POP LA UNIVERSAL 2DA ET., LIMA - LIMA - SANTA ANITA',
        'ubigeo_sunat' => '150137', 'ubigeo' => ['15', '1501', '150137'],
    ]];
}

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->cashier = User::factory()->create();
});

test('admin saves the token encrypted and the connection is tested automatically', function () {
    Http::fake([LOOKUP.'/ruc' => Http::response(rucResponse())]);

    $this->actingAs($this->admin)
        ->put('/settings/lookup', ['base_url' => LOOKUP, 'token' => 'tok-secreto-1234'])
        ->assertSessionHas('success', fn ($m) => str_contains($m, 'Conexión correcta'));

    expect(Setting::where('key', 'lookup_token')->value('value'))->not->toBe('tok-secreto-1234')
        ->and(Settings::get('lookup_token'))->toBe('tok-secreto-1234');

    Http::assertSent(fn (Request $r) => $r->url() === LOOKUP.'/ruc'
        && $r->header('Authorization')[0] === 'Bearer tok-secreto-1234'
        && $r['ruc'] === '20131312955');

    $this->actingAs($this->admin)->get('/settings/lookup')
        ->assertInertia(fn (Assert $p) => $p->component('settings/lookup')->where('hasToken', true)->where('tokenHint', '…1234'));
});

test('an invalid token is reported when testing the connection', function () {
    Settings::set(['lookup_token' => 'malo']);
    Http::fake([LOOKUP.'/ruc' => Http::response(['success' => false, 'message' => 'Token inválido'], 401)]);

    $this->actingAs($this->admin)->post('/settings/lookup/test')
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'Token de consulta DNI/RUC inválido'));
});

test('cashier looks up a DNI and gets the name without comma', function () {
    Settings::set(['lookup_token' => 'tok']);
    Http::fake([LOOKUP.'/dni' => Http::response(dniResponse())]);

    $this->actingAs($this->cashier)->getJson('/customers/lookup?number=27427864')
        ->assertOk()
        ->assertJson(['source' => 'api', 'data' => [
            'doc_type' => '1', 'doc_number' => '27427864', 'name' => 'CASTILLO TERRONES JOSE PEDRO', 'address' => null, 'warning' => null,
        ]]);

    Http::assertSent(fn (Request $r) => $r['dni'] === '27427864');
});

test('ruc lookup returns full address and warns about non active taxpayers', function () {
    Settings::set(['lookup_token' => 'tok']);
    Http::fake([LOOKUP.'/ruc' => Http::response(rucResponse('SUSPENSION TEMPORAL'))]);

    $this->actingAs($this->cashier)->getJson('/customers/lookup?number=20552103816')
        ->assertOk()
        ->assertJsonPath('data.name', 'AGROLIGHT PERU S.A.C.')
        ->assertJsonPath('data.address', 'PJ. JORGE BASADRE NRO. 158 URB. POP LA UNIVERSAL 2DA ET., LIMA - LIMA - SANTA ANITA')
        ->assertJsonPath('data.warning', 'Contribuyente SUSPENSION TEMPORAL / HABIDO.');
});

test('registered customers are returned without calling the api', function () {
    Settings::set(['lookup_token' => 'tok']);
    Http::fake();
    $customer = Customer::factory()->create(['doc_number' => '27427864']);

    $this->actingAs($this->cashier)->getJson('/customers/lookup?number=27427864')
        ->assertOk()
        ->assertJson(['source' => 'local', 'customer' => ['id' => $customer->id]]);

    Http::assertNothingSent();
});

test('not found and not configured cases', function () {
    $this->actingAs($this->cashier)->getJson('/customers/lookup?number=12345678')
        ->assertOk()->assertJson(['source' => 'none']);

    Settings::set(['lookup_token' => 'tok']);
    Http::fake([LOOKUP.'/dni' => Http::response(['success' => false, 'message' => 'No se encontraron resultados'], 200)]);

    $this->actingAs($this->cashier)->getJson('/customers/lookup?number=12345678')
        ->assertNotFound()->assertJson(['message' => 'No se encontraron resultados', 'not_found' => true]);

    $this->actingAs($this->cashier)->getJson('/customers/lookup?number=123')->assertStatus(422);
});

test('cashier cannot change the lookup settings', function () {
    $this->actingAs($this->cashier)->get('/settings/lookup')->assertForbidden();
    $this->actingAs($this->cashier)->put('/settings/lookup', ['base_url' => LOOKUP, 'token' => 'x'])->assertForbidden();
});
