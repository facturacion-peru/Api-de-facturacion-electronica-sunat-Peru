<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32);
            $table->boolean('active')->default(true);
            $table->timestamps();

            // Una empresa por usuario en el MVP (A-04). Quitar este índice
            // habilita el multiempresa sin migrar datos.
            $table->unique('user_id');
            $table->index(['company_id', 'role', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_user');
    }
};
