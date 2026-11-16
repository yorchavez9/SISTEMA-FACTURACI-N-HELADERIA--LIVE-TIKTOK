<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('code', 30)->unique();
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->decimal('price', 10, 2); // precio final, IGV incluido
            $table->string('unit', 5)->default('NIU');
            $table->string('tax_affectation', 2)->default('10');
            $table->boolean('track_stock')->default(false);
            $table->decimal('stock', 10, 2)->default(0);
            $table->decimal('stock_min', 10, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category_id', 'active']);
            $table->index('name');
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('doc_type', 1); // 1=DNI, 4=CE, 6=RUC, 7=Pasaporte
            $table->string('doc_number', 15);
            $table->string('name', 200);
            $table->string('address', 255)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 120)->nullable();
            $table->timestamps();

            $table->unique(['doc_type', 'doc_number']);
            $table->index('name');
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('sold_at');
            $table->decimal('total', 10, 2);
            $table->string('payment_method', 20);
            $table->decimal('amount_received', 10, 2)->nullable();
            $table->decimal('change', 10, 2)->nullable();
            $table->string('document_type', 10)->default('ninguno');
            $table->string('status', 15)->default('completada');
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->index('sold_at');
            $table->index(['status', 'sold_at']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            // copia de los datos del producto al momento de la venta
            $table->string('product_code', 30);
            $table->string('product_name', 120);
            $table->string('unit', 5)->default('NIU');
            $table->string('tax_affectation', 2)->default('10');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('quantity', 10, 2);
            $table->decimal('total', 10, 2);

            $table->index('product_id');
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('type', 15); // sale, void, adjustment
            $table->decimal('quantity', 10, 2); // positivo entra, negativo sale
            $table->decimal('stock_after', 10, 2);
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['product_id', 'created_at']);
        });

        Schema::create('electronic_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->string('type', 2); // 01 factura, 03 boleta, 07 nota de crédito
            $table->string('series', 4);
            $table->unsignedInteger('number')->nullable(); // lo asigna el proveedor
            $table->string('provider_id', 40)->nullable();
            $table->foreignId('related_document_id')->nullable()->constrained('electronic_documents')->nullOnDelete();
            $table->json('customer_snapshot');
            $table->decimal('total', 10, 2);
            $table->string('status', 20)->default('borrador');
            $table->string('sunat_code', 10)->nullable();
            $table->text('sunat_message')->nullable();
            $table->string('hash', 120)->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('issued_at');
            $table->dateTime('last_attempt_at')->nullable();
            $table->dateTime('last_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['type', 'series', 'number']);
            $table->index('status');
            $table->index('issued_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('electronic_documents');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('settings');
    }
};
