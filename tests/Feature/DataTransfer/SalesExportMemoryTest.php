<?php

use App\DataTransfer\Exports\SalesExport;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Spec 014 · T014: la exportación de ventas recorre por bloques. Con 20 000
 * líneas, la memoria adicional se mantiene acotada (no carga todo).
 */

it('exporta 20 000 líneas de venta sin cargar todo en memoria', function (string $format) {
    Carbon::setTestNow('2026-10-07 12:00:00');
    $company = Company::factory()->withMainEstablishment()->create();
    $seller = User::factory()->forCompany($company, CompanyRole::Seller)->create();
    app(TenantContext::class)->set($company);
    $product = Product::factory()->create(['company_id' => $company->id]);

    $now = now()->toDateTimeString();
    for ($block = 0; $block < 4; $block++) {
        $tickets = [];
        for ($i = 1; $i <= 500; $i++) {
            $number = $block * 500 + $i;
            $tickets[] = [
                'company_id' => $company->id, 'number' => $number, 'status' => 'issued', 'seller_id' => $seller->id,
                'payment_method' => 'cash', 'subtotal' => '100.00', 'discount_total' => '0.00', 'total' => '100.00',
                'idempotency_key' => (string) Str::uuid(), 'issued_at' => Carbon::parse('2026-10-01')->addMinutes($number)->toDateTimeString(),
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        DB::table('tickets')->insert($tickets);
    }

    $lines = [];
    foreach (DB::table('tickets')->where('company_id', $company->id)->pluck('id') as $ticketId) {
        for ($position = 1; $position <= 10; $position++) {
            $lines[] = [
                'company_id' => $company->id, 'ticket_id' => $ticketId, 'product_id' => $product->id, 'position' => $position,
                'product_code' => $product->code, 'product_name' => 'Producto con un nombre de largo normal', 'unit' => 'NIU', 'igv_affectation' => '10',
                'quantity' => '1.000', 'unit_price' => '10.00', 'gross_amount' => '10.00', 'discount' => '0.00', 'amount' => '10.00',
            ];
        }
        if (count($lines) >= 2000) {
            DB::table('ticket_lines')->insert($lines);
            $lines = [];
        }
    }
    DB::table('ticket_lines')->insert($lines);
    expect(DB::table('ticket_lines')->count())->toBe(20000);

    gc_collect_cycles();
    $before = memory_get_usage();
    memory_reset_peak_usage();
    $started = microtime(true);

    $file = app(SalesExport::class)->build($format, ['from' => '2026-10-01', 'to' => '2026-10-07', 'types' => [], 'statuses' => []]);

    $extraMb = (memory_get_peak_usage() - $before) / 1048576;
    $seconds = microtime(true) - $started;
    fwrite(STDERR, sprintf("\n[T014] %s: memoria adicional %.1f MB, %.1f s, %d documentos\n", $format, $extraMb, $seconds, $file->rows));

    expect($file->rows)->toBe(2000)
        ->and($extraMb)->toBeLessThan(48);

    if ($format === 'csv') {
        $zip = new ZipArchive;
        $zip->open($file->path);
        expect(substr_count($zip->getFromName('lineas.csv'), "\n"))->toBe(20001);
        $zip->close();
    }
    $file->delete();
    Carbon::setTestNow();
})->with(['xlsx', 'csv']);
