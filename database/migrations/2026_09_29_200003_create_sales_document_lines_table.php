<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('product_code', 32);
            $table->string('product_name');
            $table->string('unit', 3);
            $table->string('igv_affectation', 2);
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('unit_value', 20, 10);
            $table->decimal('gross_amount', 12, 2);
            $table->decimal('discount', 12, 2);
            $table->decimal('base_amount', 12, 2);
            $table->decimal('igv', 12, 2);
            $table->decimal('amount', 12, 2);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_document_lines');
    }
};
