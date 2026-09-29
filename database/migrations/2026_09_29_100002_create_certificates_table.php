<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PEM (certificado + clave privada) y contraseña cifrados en la base de
        // datos, nunca en el disco público (RF-001). Solo metadatos en claro.
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->text('pem');
            $table->text('password')->nullable();
            $table->string('subject');
            $table->char('ruc', 11);
            $table->string('serial_number')->nullable();
            $table->timestamp('valid_from');
            $table->timestamp('valid_to');
            $table->string('status', 16);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('replaced_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
