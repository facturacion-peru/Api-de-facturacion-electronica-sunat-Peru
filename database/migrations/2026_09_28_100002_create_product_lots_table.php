<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('lot_number', 40);
            $table->date('received_at');
            $table->date('expires_at')->nullable();
            $table->decimal('initial_quantity', 14, 3);
            // Caché derivada de la suma de movimientos; solo la escribe InventoryService.
            $table->decimal('remaining_quantity', 14, 3);
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->string('reference')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'lot_number']);
            $table->index(['product_id', 'expires_at', 'received_at']);
        });

        // Última barrera contra el stock negativo (RF-015). SQLite no admite
        // añadir CHECK a una tabla existente; allí lo cubre el servicio.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE product_lots ADD CONSTRAINT product_lots_remaining_non_negative CHECK (remaining_quantity >= 0)');
            DB::statement('ALTER TABLE product_lots ADD CONSTRAINT product_lots_initial_positive CHECK (initial_quantity > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_lots');
    }
};
