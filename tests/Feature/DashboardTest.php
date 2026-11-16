<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $this->get('/dashboard')->assertRedirect('/login');
    $this->get('/pos')->assertRedirect('/login');
});

test('admin can visit the dashboard', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/dashboard')
        ->assertOk();
});

test('cashier cannot visit admin sections', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/dashboard')->assertForbidden();
    $this->get('/products')->assertForbidden();
    $this->get('/reports')->assertForbidden();
    $this->get('/settings/billing')->assertForbidden();
    $this->get('/pos')->assertOk();
});

test('inactive users are logged out', function () {
    $this->actingAs(User::factory()->create(['active' => false]))
        ->get('/pos')
        ->assertRedirect('/login');

    $this->assertGuest();
});
