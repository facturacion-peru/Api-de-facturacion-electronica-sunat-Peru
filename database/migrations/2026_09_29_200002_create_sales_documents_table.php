<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('series_id')->constrained('series');
            $table->string('document_type', 2);
            $table->char('series_code', 4);
            $table->unsignedInteger('number');
            $table->string('environment', 16);
            $table->timestamp('issued_at');
            $table->foreignId('seller_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payment_method', 16);
            $table->char('currency', 3);

            // Copia del emisor y del cliente al emitir (RF-006).
            $table->char('issuer_ruc', 11);
            $table->string('issuer_name');
            $table->string('issuer_trade_name')->nullable();
            $table->string('issuer_address');
            $table->char('issuer_ubigeo', 6);
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_document_type', 1);
            $table->string('customer_document_number', 15);
            $table->string('customer_name');
            $table->string('customer_address')->nullable();

            $table->decimal('op_gravadas', 12, 2);
            $table->decimal('op_exoneradas', 12, 2);
            $table->decimal('op_inafectas', 12, 2);
            $table->decimal('igv', 12, 2);
            $table->decimal('discount_total', 12, 2);
            $table->decimal('total', 12, 2);

            // Envío a SUNAT (RF-010–013).
            $table->string('status', 16);
            $table->string('sunat_code', 10)->nullable();
            $table->text('sunat_message')->nullable();
            $table->json('sunat_notes')->nullable();
            $table->longText('xml');
            $table->string('hash', 64);
            $table->longText('cdr')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            // Lease: evita dos envíos simultáneos del mismo comprobante (RF-012).
            $table->timestamp('locked_until')->nullable();

            $table->uuid('idempotency_key');
            $table->timestamps();

            $table->unique(['company_id', 'series_id', 'number']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'issued_at']);
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_documents');
    }
};
