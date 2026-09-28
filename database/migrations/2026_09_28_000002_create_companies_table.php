<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sin credenciales SUNAT: esas viven cifradas y aparte (spec 004).
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->char('ruc', 11)->unique();
            $table->string('razon_social');
            $table->string('nombre_comercial')->nullable();
            $table->string('person_type', 16);
            $table->string('tax_regime', 16);
            $table->string('email');
            $table->string('phone', 32)->nullable();
            $table->string('logo_path')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
