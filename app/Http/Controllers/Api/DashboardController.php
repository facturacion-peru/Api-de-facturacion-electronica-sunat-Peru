<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Panel de inicio (spec 011): indicadores del día en una sola petición (CE-002). */
class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $dashboard->summary($request->user())]);
    }
}
