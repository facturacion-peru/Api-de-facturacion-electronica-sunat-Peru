<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\IndexAuditLogRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Support\Carbon;

/** Auditoría de la propia empresa, solo lectura (HU-6). */
class AuditLogController extends Controller
{
    public function index(IndexAuditLogRequest $request): ApiCollection
    {
        $filters = $request->validated();

        $logs = AuditLog::with('actor')
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->when($filters['actor_id'] ?? null, fn ($q, $actor) => $q->where('actor_id', $actor))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', Carbon::parse($to)->endOfDay()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return AuditLogResource::collection($logs);
    }
}
