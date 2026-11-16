<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Motivo de notas de crédito (cat. 09) y débito (cat. 10).
        Schema::table('electronic_documents', function (Blueprint $table) {
            $table->string('reason_code', 2)->nullable()->after('related_document_id');
            $table->string('reason', 255)->nullable()->after('reason_code');
        });

        // Comunicaciones de baja (RA: facturas y notas) y resúmenes de anulación (RC: boletas).
        Schema::create('document_voidings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('electronic_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 2); // RA | RC
            $table->string('reason', 255);
            $table->string('provider_id', 40)->nullable();
            $table->string('identifier', 40)->nullable(); // RA-20261116-001
            $table->string('ticket', 40)->nullable();
            $table->string('status', 20)->default('pendiente');
            $table->string('sunat_code', 10)->nullable();
            $table->text('sunat_message')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->dateTime('last_checked_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_voidings');
        Schema::table('electronic_documents', function (Blueprint $table) {
            $table->dropColumn(['reason_code', 'reason']);
        });
    }
};
