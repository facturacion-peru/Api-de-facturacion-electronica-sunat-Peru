<?php

namespace App\Http\Controllers\Api;

use App\Establishments\EstablishmentAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexDashboardRequest;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;

/** Panel de inicio (spec 011): indicadores del día en una sola petición (CE-002). */
class DashboardController extends Controller
{
    public function __invoke(IndexDashboardRequest $request, DashboardService $dashboard, EstablishmentAccess $access): JsonResponse
    {
        $establishments = $access->filter($request->user(), $request->integer('establishment_id') ?: null);

        return response()->json(['success' => true, 'data' => $dashboard->summary($request->user(), $establishments)]);
    }
}
