<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // En el MVP solo existe el establecimiento principal (anexo 0000, A-07).
        Schema::create('establishments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->char('code', 4);
            $table->string('name');
            $table->string('address');
            $table->string('ubigeo', 6);
            $table->boolean('is_main')->default(false);
            $table->timestamps();

            $table->foreign('ubigeo')->references('id')->on('ubi_distritos');
            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('establishments');
    }
};
