<?php

namespace App\Audit;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Único punto de escritura de la auditoría (RF-040/041).
 *
 * Nunca guarda valores de secretos: elimina las claves sensibles, también
 * anidadas, y los atributos `$hidden` del modelo auditado.
 */
class AuditLogger
{
    /** Fragmentos que marcan una clave como sensible (sin distinguir mayúsculas). */
    private const SENSITIVE = ['password', 'token', 'secret', 'clave', 'certificado'];

    public function __construct(private TenantContext $tenant) {}

    /**
     * @param  array<string, mixed>  $changes
     */
    public function record(
        string $action,
        ?Model $auditable = null,
        array $changes = [],
        ?Company $company = null,
        ?User $actor = null,
    ): AuditLog {
        $hidden = $auditable?->getHidden() ?? [];
        // Se lee en cada llamada: el logger puede resolverse antes de autenticar.
        $request = request();

        return AuditLog::create([
            'company_id' => $company?->id ?? $this->tenant->id() ?? $this->companyOf($auditable),
            'actor_id' => ($actor ?? auth()->user())?->getAuthIdentifier(),
            'action' => $action,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'changes' => $this->sanitize($changes, $hidden) ?: null,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
        ]);
    }

    private function companyOf(?Model $auditable): ?int
    {
        return match (true) {
            $auditable instanceof Company => $auditable->id,
            $auditable !== null && $auditable->getAttribute('company_id') !== null => (int) $auditable->getAttribute('company_id'),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $hidden
     * @return array<string, mixed>
     */
    private function sanitize(array $data, array $hidden): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (in_array($key, $hidden, true) || $this->isSensitive((string) $key)) {
                continue;
            }

            $clean[$key] = is_array($value) ? $this->sanitize($value, []) : $value;
        }

        return $clean;
    }

    private function isSensitive(string $key): bool
    {
        $key = mb_strtolower($key);

        foreach (self::SENSITIVE as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
