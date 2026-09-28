<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('boletas', function (Blueprint $table) {
            // Agregar columna para el método de envío (individual o resumen_diario)
            if (! Schema::hasColumn('boletas', 'metodo_envio')) {
                $table->string('metodo_envio', 20)->default('individual')->after('moneda');
            }

            // Agregar relación con resumen diario (nullable porque solo aplica si metodo_envio es resumen_diario)
            // Sin foreign key por ahora, se agregará cuando exista la tabla daily_summaries
            if (! Schema::hasColumn('boletas', 'daily_summary_id')) {
                $table->unsignedBigInteger('daily_summary_id')->nullable()->after('client_id')->index();
            }

            // Agregar columnas para impuestos adicionales.
            // Pueden existir ya por las migraciones 2025_09_04_181406 y 2025_09_06_144953.
            if (! Schema::hasColumn('boletas', 'mto_igv_gratuitas')) {
                $table->decimal('mto_igv_gratuitas', 12, 2)->default(0)->after('mto_oper_gratuitas');
            }

            if (! Schema::hasColumn('boletas', 'mto_base_ivap')) {
                $table->decimal('mto_base_ivap', 12, 2)->default(0)->after('mto_igv');
            }

            if (! Schema::hasColumn('boletas', 'mto_ivap')) {
                $table->decimal('mto_ivap', 12, 2)->default(0)->after('mto_base_ivap');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('boletas', function (Blueprint $table) {
            // Solo se revierten las columnas propias de esta migración; las de IVAP
            // y gratuitas pertenecen a migraciones anteriores.
            foreach (['metodo_envio', 'daily_summary_id'] as $column) {
                if (Schema::hasColumn('boletas', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
