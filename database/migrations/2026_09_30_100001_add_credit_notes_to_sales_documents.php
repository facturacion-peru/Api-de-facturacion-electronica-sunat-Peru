<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Notas de crédito y descarte de rechazados (spec 007). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_documents', function (Blueprint $table) {
            // Solo notas (tipo 07): el comprobante que modifican y el motivo.
            $table->foreignId('reference_document_id')->nullable()->after('series_id')->constrained('sales_documents');
            $table->string('note_reason_code', 2)->nullable();
            $table->string('note_reason')->nullable();
            $table->boolean('restock')->nullable();
            // Solo facturas y boletas: resultado de sus notas aceptadas.
            $table->string('correction_status', 20)->default('none');
            // Rechazados dados de baja internamente (A-42).
            $table->timestamp('discarded_at')->nullable();
            $table->foreignId('discarded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('discard_reason')->nullable();

            $table->index('reference_document_id');
        });

        Schema::table('sales_document_lines', function (Blueprint $table) {
            // Solo líneas de nota: la línea original que corrigen.
            $table->foreignId('reference_line_id')->nullable()->after('product_id')->constrained('sales_document_lines');
            $table->index('reference_line_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales_document_lines', function (Blueprint $table) {
            $table->dropIndex(['reference_line_id']);
            $table->dropConstrainedForeignId('reference_line_id');
        });

        Schema::table('sales_documents', function (Blueprint $table) {
            $table->dropIndex(['reference_document_id']);
            $table->dropConstrainedForeignId('reference_document_id');
            $table->dropConstrainedForeignId('discarded_by');
            $table->dropColumn(['note_reason_code', 'note_reason', 'restock', 'correction_status', 'discarded_at', 'discard_reason']);
        });
    }
};
