<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentActionController;
use App\Http\Controllers\ElectronicDocumentController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\Settings\BillingSettingsController;
use App\Http\Controllers\Settings\BusinessSettingsController;
use App\Http\Controllers\Settings\LookupSettingsController;
use App\Http\Controllers\Settings\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/pos')->name('home');

Route::middleware('auth')->group(function () {
    // Punto de venta (todos los usuarios)
    Route::get('pos', [PosController::class, 'index'])->name('pos.index');
    Route::post('pos', [PosController::class, 'store'])->name('pos.store');

    // Ventas
    Route::get('sales', [SaleController::class, 'index'])->name('sales.index');
    Route::get('sales/{sale}', [SaleController::class, 'show'])->name('sales.show');
    Route::get('sales/{sale}/ticket', [SaleController::class, 'ticket'])->name('sales.ticket');
    Route::post('sales/{sale}/documents', [ElectronicDocumentController::class, 'store'])->name('sales.documents.store');

    // Clientes
    Route::get('customers/search', [CustomerController::class, 'search'])->name('customers.search');
    Route::get('customers/lookup', [CustomerController::class, 'lookup'])->middleware('throttle:30,1')->name('customers.lookup');
    Route::post('customers/quick', [CustomerController::class, 'quickStore'])->name('customers.quick');
    Route::resource('customers', CustomerController::class)->only(['index', 'store', 'update']);

    // Comprobantes electrónicos
    Route::get('documents', [ElectronicDocumentController::class, 'index'])->name('documents.index');
    Route::get('documents/{document}', [ElectronicDocumentController::class, 'show'])->name('documents.show');
    Route::get('documents/{document}/status', [ElectronicDocumentController::class, 'status'])->name('documents.status');
    Route::post('documents/{document}/refresh', [ElectronicDocumentController::class, 'refresh'])->name('documents.refresh');
    Route::post('documents/{document}/resend', [ElectronicDocumentController::class, 'resend'])->name('documents.resend');
    Route::put('documents/{document}', [ElectronicDocumentController::class, 'update'])->name('documents.update');
    Route::post('documents/{document}/consult', [ElectronicDocumentController::class, 'consult'])->name('documents.consult');
    Route::get('documents/{document}/download/{kind}', [ElectronicDocumentController::class, 'download'])
        ->whereIn('kind', ['pdf', 'xml', 'cdr'])->name('documents.download');

    // Solo administrador
    Route::middleware('admin')->group(function () {
        Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::post('sales/{sale}/void', [SaleController::class, 'void'])->name('sales.void');

        // Notas de crédito/débito y comunicación de baja
        Route::get('documents/{document}/note-data', [DocumentActionController::class, 'noteData'])->name('documents.note-data');
        Route::post('documents/{document}/credit-note', [DocumentActionController::class, 'creditNote'])->name('documents.credit-note');
        Route::post('documents/{document}/debit-note', [DocumentActionController::class, 'debitNote'])->name('documents.debit-note');
        Route::post('documents/{document}/voiding', [DocumentActionController::class, 'voiding'])->name('documents.voiding');

        Route::resource('categories', CategoryController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('products', ProductController::class)->except(['show']);
        Route::post('products/{product}/stock', [ProductController::class, 'adjustStock'])->name('products.stock');
        Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy');

        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');

        Route::get('settings/business', [BusinessSettingsController::class, 'edit'])->name('settings.business');
        Route::put('settings/business', [BusinessSettingsController::class, 'update']);
        Route::get('settings/billing', [BillingSettingsController::class, 'edit'])->name('settings.billing');
        Route::put('settings/billing', [BillingSettingsController::class, 'update']);
        Route::post('settings/billing/test', [BillingSettingsController::class, 'test'])->name('settings.billing.test');
        Route::get('settings/lookup', [LookupSettingsController::class, 'edit'])->name('settings.lookup');
        Route::put('settings/lookup', [LookupSettingsController::class, 'update']);
        Route::delete('settings/lookup', [LookupSettingsController::class, 'destroy']);
        Route::post('settings/lookup/test', [LookupSettingsController::class, 'test'])->name('settings.lookup.test');
        Route::get('settings/users', [UserController::class, 'index'])->name('settings.users');
        Route::post('settings/users', [UserController::class, 'store'])->name('settings.users.store');
        Route::put('settings/users/{user}', [UserController::class, 'update'])->name('settings.users.update');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
