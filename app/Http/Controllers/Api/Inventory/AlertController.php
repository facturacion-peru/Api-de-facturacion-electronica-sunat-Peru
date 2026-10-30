<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Establishments\EstablishmentAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\IndexAlertRequest;
use App\Services\InventoryAlertService;
use Illuminate\Http\JsonResponse;

/** Alertas de inventario de la empresa (HU-5.2, HU-5.3, RF-021). */
class AlertController extends Controller
{
    public function index(IndexAlertRequest $request, InventoryAlertService $alerts, EstablishmentAccess $access): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $alerts->alerts($access->filter($request->user(), $request->integer('establishment_id') ?: null)),
            'meta' => ['expiry_warning_days' => $alerts->warningDays()],
        ]);
    }
}
