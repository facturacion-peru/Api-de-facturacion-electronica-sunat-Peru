<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consulta de DNI y RUC (spec 018). Un cliente traído del servicio queda
 * verificado (A-92); lo que devuelve el servicio se guarda en una tabla común
 * de la plataforma (A-93 ⚖️), y cada llamada real deja una fila de uso (A-96).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('verified_at')->nullable()->after('address');
        });

        // De la plataforma: sin company_id. Solo lo que va en el comprobante.
        Schema::create('document_lookups', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 1);
            $table->string('document_number', 15);
            $table->string('name');
            $table->string('address')->nullable();
            $table->char('ubigeo', 6)->nullable();
            $table->string('ruc_status', 40)->nullable();
            $table->string('ruc_condition', 40)->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['document_type', 'document_number']);
        });

        // Una fila por llamada real al servicio, sin el número del documento.
        Schema::create('document_lookup_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('document_type', 1);
            $table->string('outcome', 16);
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_lookup_requests');
        Schema::dropIfExists('document_lookups');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('verified_at');
        });
    }
};
