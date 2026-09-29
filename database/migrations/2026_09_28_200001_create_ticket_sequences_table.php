<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Una fila por empresa, bloqueada con FOR UPDATE al numerar: sin huecos
        // ni duplicados; si la venta falla, el número no se consume (RF-002).
        Schema::create('ticket_sequences', function (Blueprint $table) {
            $table->foreignId('company_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('last_number')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_sequences');
    }
};
