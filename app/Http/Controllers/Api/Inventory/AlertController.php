<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Services\InventoryAlertService;
use Illuminate\Http\JsonResponse;

/** Alertas de inventario de la empresa (HU-5.2, HU-5.3, RF-021). */
class AlertController extends Controller
{
    public function index(InventoryAlertService $alerts): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $alerts->alerts(),
            'meta' => ['expiry_warning_days' => $alerts->warningDays()],
        ]);
    }
}
