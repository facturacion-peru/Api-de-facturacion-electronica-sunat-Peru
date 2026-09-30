<?php

namespace App\Platform;

use App\Enums\CompanyRole;
use App\Enums\SalesDocumentStatus;
use App\Enums\SunatStatus;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Invitation;
use App\Services\SunatConfigService;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Metadatos y contadores de las empresas para el panel de la plataforma
 * (spec 006, A-37). Lee entre empresas con consultas agregadas, nunca
 * filas de negocio.
 */
final class CompanySummaries
{
    public function __construct(
        private SunatConfigService $sunat,
        private TenantContext $tenant,
    ) {}

    /** Añade a la consulta los contadores de cada empresa. */
    public function withCounts(Builder $query): Builder
    {
        return $query->addSelect([
            'users_count' => DB::table('company_user')->selectRaw('count(*)')->whereColumn('company_id', 'companies.id'),
            'pending_documents' => $this->documents([SalesDocumentStatus::Pending, SalesDocumentStatus::Sent]),
            'rejected_documents' => $this->documents([SalesDocumentStatus::Rejected]),
        ]);
    }

    /** «Con problemas de emisión»: pendientes, rechazados o SUNAT sin validar. */
    public function withIssues(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q
            ->whereExists(fn ($s) => $s->from('sales_documents')->whereColumn('company_id', 'companies.id')
                ->whereIn('status', [SalesDocumentStatus::Pending->value, SalesDocumentStatus::Sent->value, SalesDocumentStatus::Rejected->value]))
            ->orWhereNotExists(fn ($s) => $s->from('sunat_settings')->whereColumn('company_id', 'companies.id')
                ->where('status', SunatStatus::Validated->value)));
    }

    /** @return array{SunatStatus, ?string} estado efectivo, calculado en el contexto de la empresa */
    public function sunatStatus(Company $company): array
    {
        return $this->tenant->run($company, fn () => $this->sunat->effectiveStatus($company));
    }

    /** @return array{name: ?string, email: ?string, invitation: string} */
    public function admin(Company $company): array
    {
        $membership = CompanyMembership::withoutTenancy()->with('user')
            ->where('company_id', $company->id)->where('role', CompanyRole::CompanyAdmin)->orderBy('id')->first();

        if ($membership) {
            return ['name' => $membership->user->name, 'email' => $membership->user->email, 'invitation' => 'accepted'];
        }

        $invitation = Invitation::withoutTenancy()->where('company_id', $company->id)
            ->where('role', CompanyRole::CompanyAdmin)->whereNull('accepted_at')->latest('id')->first();

        return [
            'name' => null,
            'email' => $invitation?->email,
            'invitation' => $invitation === null ? 'none' : ($invitation->isPending() ? 'pending' : 'expired'),
        ];
    }

    public function activeUsers(Company $company): int
    {
        return DB::table('company_user')->where('company_id', $company->id)->where('active', true)->count();
    }

    /** Última venta o comprobante: sin revelar cuáles. */
    public function lastActivityAt(Company $company): ?string
    {
        $dates = array_filter([
            DB::table('tickets')->where('company_id', $company->id)->max('issued_at'),
            DB::table('sales_documents')->where('company_id', $company->id)->max('issued_at'),
        ]);

        return $dates === [] ? null : \Illuminate\Support\Carbon::parse(max($dates))->toIso8601String();
    }

    /** @param  list<SalesDocumentStatus>  $statuses */
    private function documents(array $statuses): \Illuminate\Database\Query\Builder
    {
        return DB::table('sales_documents')->selectRaw('count(*)')->whereColumn('company_id', 'companies.id')
            ->whereIn('status', array_map(fn ($s) => $s->value, $statuses));
    }
}
