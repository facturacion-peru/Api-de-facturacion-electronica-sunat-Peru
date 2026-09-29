<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Copia de los datos del producto al vender (RF-009); la afectación al
        // IGV queda para la futura conversión en comprobante (A-17).
        Schema::create('ticket_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('product_code', 32);
            $table->string('product_name');
            $table->string('unit', 3);
            $table->char('igv_affectation', 2);
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('gross_amount', 12, 2);
            $table->decimal('discount', 12, 2);
            $table->decimal('amount', 12, 2);

            $table->index(['ticket_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_lines');
    }
};
