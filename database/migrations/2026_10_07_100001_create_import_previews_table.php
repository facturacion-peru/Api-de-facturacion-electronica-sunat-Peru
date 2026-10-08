<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vistas previas de importación (spec 014): las filas ya validadas y
     * normalizadas de un archivo, a la espera de confirmación. El archivo
     * subido no se guarda. Caducan a los 30 minutos y se borran al día.
     */
    public function up(): void
    {
        Schema::create('import_previews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('mode', 10);
            $table->json('rows');
            $table->json('summary');
            $table->json('errors');
            $table->json('warnings');
            $table->json('changes');
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_previews');
    }
};
