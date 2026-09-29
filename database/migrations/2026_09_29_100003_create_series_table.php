<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // El correlativo solo avanza con la emisión (spec 005), bloqueando la fila.
        Schema::create('series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('establishment_id')->constrained()->cascadeOnDelete();
            $table->char('document_type', 2);
            $table->char('code', 4);
            $table->unsignedInteger('last_number')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'document_type', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('series');
    }
};
