<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Enums\AdjustmentReason;
use App\Enums\IgvAffectation;
use App\Enums\UnitOfMeasure;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** Catálogos del inventario: datos de referencia iguales para todas las empresas. */
class CatalogController extends Controller
{
    public function inventory(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'units' => array_map(fn (UnitOfMeasure $u) => [
                    'code' => $u->value, 'label' => $u->label(), 'allows_decimals' => $u->allowsDecimals(),
                ], UnitOfMeasure::cases()),
                'igv_affectations' => array_map(fn (IgvAffectation $a) => [
                    'code' => $a->value, 'label' => $a->label(),
                ], IgvAffectation::cases()),
                'adjustment_reasons' => array_map(fn (AdjustmentReason $r) => [
                    'code' => $r->value, 'label' => $r->label(),
                ], AdjustmentReason::cases()),
            ],
        ]);
    }
}
