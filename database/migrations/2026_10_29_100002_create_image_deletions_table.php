<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Objetos del disco de imágenes que no se pudieron borrar (spec 016,
     * A-82). `images:cleanup` los reintenta cada día; así no quedan huérfanos
     * sin tener que listar el bucket. Es de la plataforma, no de una empresa.
     */
    public function up(): void
    {
        Schema::create('image_deletions', function (Blueprint $table) {
            $table->id();
            $table->string('path')->unique();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_deletions');
    }
};
