<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** @mixin AuditLog */
class AuditLogResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'actor' => $this->actor ? ['id' => $this->actor->id, 'name' => $this->actor->name] : null,
            // Nombre corto (user, company, invitation), no la clase PHP.
            'auditable_type' => $this->auditable_type ? Str::snake(class_basename($this->auditable_type)) : null,
            'auditable_id' => $this->auditable_id,
            'changes' => $this->changes,
            'ip' => $this->ip,
            'user_agent' => $this->user_agent,
            'created_at' => $this->created_at->toIso8601String(),
            // Solo en la auditoría de la plataforma (spec 006): empresa afectada.
            'company' => $this->when(array_key_exists('company_summary', $this->resource->getAttributes()), fn () => $this->company_summary),
        ];
    }
}
