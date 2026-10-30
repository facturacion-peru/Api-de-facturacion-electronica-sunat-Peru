<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Varios establecimientos (spec 017). Todo lo que pasa en un local lo lleva
 * escrito; lo existente queda en el principal de su empresa y los vendedores
 * asignados a él (A-91).
 */
return new class extends Migration
{
    /** Tablas que pasan a tener local, en el orden en que se rellenan. */
    private const TABLES = ['product_lots', 'inventory_movements', 'tickets', 'sales_documents'];

    public function up(): void
    {
        Schema::table('establishments', function (Blueprint $table) {
            $table->boolean('active')->default(true)->after('is_main');
        });

        // Solo vendedores: el administrador de empresa trabaja en todos (A-87).
        Schema::create('establishment_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('establishment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['establishment_id', 'user_id']);
            $table->index(['company_id', 'user_id']);
        });

        Schema::table('invitations', function (Blueprint $table) {
            $table->json('establishment_ids')->nullable()->after('role');
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_establishment_id')->constrained('establishments');
            $table->foreignId('to_establishment_id')->constrained('establishments');
            $table->decimal('quantity', 14, 3);
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at']);
        });

        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('establishment_id')->nullable()->after('company_id');
            });
        }

        $this->backfill();

        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->foreignId('establishment_id')->nullable(false)->change();
                $table->foreign('establishment_id')->references('id')->on('establishments');
                $table->index(['establishment_id', $name === 'product_lots' ? 'product_id' : 'created_at']);
            });
        }

        // Tras un traslado, el mismo lote existe en dos locales.
        Schema::table('product_lots', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'lot_number']);
            $table->unique(['product_id', 'establishment_id', 'lot_number']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_distinct CHECK (from_establishment_id <> to_establishment_id)');
            DB::statement('ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_positive CHECK (quantity > 0)');
        }
    }

    public function down(): void
    {
        // Tras un traslado, un lote queda repartido entre locales y el esquema
        // anterior no puede representarlo: el único antiguo solo vuelve si cabe.
        $split = DB::table('product_lots')->select('product_id', 'lot_number')
            ->groupBy('product_id', 'lot_number')->havingRaw('count(*) > 1')->exists();

        Schema::table('product_lots', function (Blueprint $table) use ($split) {
            $table->dropUnique(['product_id', 'establishment_id', 'lot_number']);

            if (! $split) {
                $table->unique(['product_id', 'lot_number']);
            }
        });

        foreach (array_reverse(self::TABLES) as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropForeign(['establishment_id']);
                $table->dropIndex(['establishment_id', $name === 'product_lots' ? 'product_id' : 'created_at']);
                $table->dropColumn('establishment_id');
            });
        }

        Schema::dropIfExists('stock_transfers');

        Schema::table('invitations', function (Blueprint $table) {
            $table->dropColumn('establishment_ids');
        });

        Schema::dropIfExists('establishment_user');

        Schema::table('establishments', function (Blueprint $table) {
            $table->dropColumn('active');
        });
    }

    private function backfill(): void
    {
        $main = DB::table('establishments')->where('is_main', true)->pluck('id', 'company_id');

        foreach ($main as $companyId => $establishmentId) {
            foreach (['product_lots', 'inventory_movements', 'tickets'] as $name) {
                DB::table($name)->where('company_id', $companyId)->update(['establishment_id' => $establishmentId]);
            }
        }

        // El comprobante es del local de su serie (hoy siempre el principal).
        DB::table('sales_documents')->update([
            'establishment_id' => DB::table('series')->select('establishment_id')->whereColumn('series.id', 'sales_documents.series_id'),
        ]);

        $sellers = DB::table('company_user')->where('role', 'seller')->get(['company_id', 'user_id']);

        foreach ($sellers as $seller) {
            if (isset($main[$seller->company_id])) {
                DB::table('establishment_user')->insert([
                    'company_id' => $seller->company_id,
                    'establishment_id' => $main[$seller->company_id],
                    'user_id' => $seller->user_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
};
