<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\IndexPlatformAuditRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Company;

/** Historial de acciones de los administradores de la plataforma (spec 006, HU-6). */
class PlatformAuditController extends Controller
{
    public function index(IndexPlatformAuditRequest $request): ApiCollection
    {
        $logs = AuditLog::withoutTenancy()
            ->with('actor')
            ->whereHas('actor', fn ($q) => $q->where('is_platform_admin', true))
            ->when($request->validated('action'), fn ($q, $action) => $q->where('action', $action))
            ->when($request->validated('company_id'), fn ($q, $company) => $q->where('company_id', $company))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(50)->withQueryString();

        $companies = Company::whereIn('id', $logs->pluck('company_id')->filter()->unique())->pluck('razon_social', 'id');

        return AuditLogResource::collection($logs->through(function (AuditLog $log) use ($companies) {
            $log->setAttribute('company_summary', $log->company_id ? ['id' => $log->company_id, 'razon_social' => $companies[$log->company_id] ?? null] : null);

            return $log;
        }));
    }
}
