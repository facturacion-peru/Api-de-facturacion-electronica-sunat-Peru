<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('status', 16);
            $table->foreignId('seller_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('customer_name')->nullable();
            $table->string('customer_document', 20)->nullable();
            $table->string('payment_method', 16);
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount_total', 12, 2);
            $table->decimal('total', 12, 2);
            // Reintentos del frontend: la misma clave devuelve el mismo ticket (RF-008).
            $table->uuid('idempotency_key');
            $table->timestamp('issued_at');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
