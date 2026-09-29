<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Inmutable: sin updated_at; el modelo impide update y delete (RF-011, RF-016).
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lot_id')->constrained('product_lots')->cascadeOnDelete();
            $table->string('type', 16);
            $table->decimal('quantity', 14, 3);
            $table->decimal('lot_balance_after', 14, 3);
            $table->decimal('product_balance_after', 14, 3);
            $table->string('reason', 16)->nullable();
            $table->string('note')->nullable();
            $table->nullableMorphs('source');
            $table->foreignId('reverses_id')->nullable()->unique()->constrained('inventory_movements')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'created_at']);
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
