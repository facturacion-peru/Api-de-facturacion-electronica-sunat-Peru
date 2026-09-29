<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cada intento de envío, inmutable (RF-013).
        Schema::create('sunat_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_document_id')->constrained()->cascadeOnDelete();
            $table->string('trigger', 16);
            $table->timestamp('started_at');
            $table->unsignedInteger('duration_ms');
            $table->string('result', 16);
            $table->string('code', 10)->nullable();
            $table->text('message')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->index(['sales_document_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sunat_submissions');
    }
};
