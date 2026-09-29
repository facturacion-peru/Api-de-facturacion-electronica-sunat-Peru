<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // La clave SOL se guarda cifrada (cast encrypted, APP_KEY): texto, no string.
        Schema::create('sunat_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('environment', 16);
            $table->string('status', 16);
            $table->string('sol_user', 20)->nullable();
            $table->text('sol_password')->nullable();
            $table->timestamp('sol_verified_at')->nullable();
            $table->timestamp('last_validated_at')->nullable();
            $table->string('last_validation_error')->nullable();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sunat_settings');
    }
};
