<?php

use App\Enums\AdjustmentReason;
use App\Enums\MovementType;
use App\Inventory\Exceptions\InsufficientStock;
use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\User;
use App\Services\InventoryService;
use App\Support\Decimal;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
 * T070 · CE-001: tras cualquier combinación de operaciones, para todo lote
 * saldo = suma de sus movimientos, y para todo producto stock = suma de sus
 * lotes. Secuencia aleatoria reproducible (semilla fija).
 */

it('mantiene saldos consistentes tras 300 operaciones aleatorias', function () {
    mt_srand(2026);
    $company = Company::factory()->withMainEstablishment()->create();
    $actor = User::factory()->forCompany($company)->create();
    app(TenantContext::class)->set($company); // como en una petición real
    $inventory = app(InventoryService::class);

    $products = [
        Product::factory()->create(['company_id' => $company->id]),
        Product::factory()->byWeight()->create(['company_id' => $company->id]),
        Product::factory()->tracksExpiry()->create(['company_id' => $company->id]),
    ];

    $quantity = fn (Product $p, int $max) => $p->unit->allowsDecimals()
        ? number_format(mt_rand(1, $max * 1000) / 1000, 3, '.', '')
        : (string) mt_rand(1, $max);

    $stats = ['entry' => 0, 'sale' => 0, 'insufficient' => 0, 'adjustment' => 0, 'reversal' => 0, 'rejected' => 0];

    for ($i = 0; $i < 300; $i++) {
        $product = $products[mt_rand(0, 2)];

        try {
            match (mt_rand(1, 10)) {
                1, 2, 3 => $inventory->registerEntry($product, [
                    'quantity' => $quantity($product, 20),
                    'received_at' => today()->subDays(mt_rand(0, 30))->toDateString(),
                    'expires_at' => $product->tracks_expiry ? today()->addDays(mt_rand(-5, 90))->toDateString() : null,
                ], $actor) && $stats['entry']++,
                4, 5, 6, 7 => DB::transaction(fn () => $inventory->consume($product, $quantity($product, 15), null, $actor)) && $stats['sale']++,
                8 => ($lot = ProductLot::withoutTenancy()->where('product_id', $product->id)->inRandomOrder()->first())
                    ? $inventory->adjust($lot, (mt_rand(0, 1) ? '' : '-').$quantity($product, 5), AdjustmentReason::Count, null, $actor) && $stats['adjustment']++
                    : null,
                default => ($movement = InventoryMovement::withoutTenancy()
                    ->where('product_id', $product->id)
                    ->where('type', '!=', MovementType::Reversal)
                    ->whereDoesntHave('reversal')
                    ->inRandomOrder()->first())
                    ? $inventory->reverse($movement, AdjustmentReason::Error, null, $actor) && $stats['reversal']++
                    : null,
            };
        } catch (InsufficientStock) {
            $stats['insufficient']++;
        } catch (ValidationException) {
            $stats['rejected']++;
        }
    }

    // Se ejercitaron todos los caminos.
    expect($stats['entry'])->toBeGreaterThan(0)
        ->and($stats['sale'])->toBeGreaterThan(0)
        ->and($stats['insufficient'])->toBeGreaterThan(0)
        ->and($stats['adjustment'])->toBeGreaterThan(0)
        ->and($stats['reversal'])->toBeGreaterThan(0);

    foreach (ProductLot::withoutTenancy()->get() as $lot) {
        $sum = Decimal::sum(InventoryMovement::withoutTenancy()->where('lot_id', $lot->id)->pluck('quantity'));

        expect($lot->remaining_quantity)->toBe($sum, "Lote {$lot->lot_number}")
            ->and(bccomp($lot->remaining_quantity, '0', 3))->toBeGreaterThanOrEqual(0);
    }

    foreach ($products as $product) {
        $stock = Decimal::sum(ProductLot::withoutTenancy()->where('product_id', $product->id)->pluck('remaining_quantity'));
        $last = InventoryMovement::withoutTenancy()->where('product_id', $product->id)->latest('id')->first();

        expect($last?->product_balance_after ?? '0.000')->toBe($stock, "Producto {$product->code}");
    }
});
