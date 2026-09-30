<?php

namespace App\Http\Controllers\Api\Platform;

use App\Enums\CompanyRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\DeactivateCompanyRequest;
use App\Http\Requests\Platform\IndexCompanyRequest;
use App\Http\Requests\Platform\StoreCompanyRequest;
use App\Http\Requests\Platform\UpdateCompanyRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\PlatformCompanyDetailResource;
use App\Http\Resources\PlatformCompanyResource;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Invitation;
use App\Platform\CompanySummaries;
use App\Services\CompanyService;
use App\Services\InvitationService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Gestión de empresas por el administrador de la plataforma (specs 001 y 006). */
class CompanyController extends Controller
{
    public function __construct(
        private CompanyService $companies,
        private CompanySummaries $summaries,
    ) {}

    /** HU-1.1/1.2: metadatos y contadores, con búsqueda y filtros. */
    public function index(IndexCompanyRequest $request): ApiCollection
    {
        $search = mb_strtolower(trim((string) $request->validated('search')));

        $query = Company::query()->select('companies.*')
            ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner
                ->where('ruc', 'like', "{$search}%")
                ->orWhereRaw('LOWER(razon_social) LIKE ?', ["%{$search}%"])
                ->orWhereRaw('LOWER(nombre_comercial) LIKE ?', ["%{$search}%"])))
            ->when($request->validated('status'), fn ($q, $status) => $q->where('active', $status === 'active'))
            ->when($request->boolean('issues'), fn ($q) => $this->summaries->withIssues($q));

        return PlatformCompanyResource::collection(
            $this->summaries->withCounts($query)->orderBy('razon_social')->paginate(20)->withQueryString()
        );
    }

    public function show(Company $company): PlatformCompanyDetailResource
    {
        return $this->detail($company);
    }

    public function update(UpdateCompanyRequest $request, Company $company): PlatformCompanyDetailResource
    {
        return $this->detail($this->companies->update($company, $request->validated(), $request->user()));
    }

    public function activate(Request $request, Company $company): PlatformCompanyDetailResource
    {
        return $this->detail($this->companies->setActive($company, true, $request->user()));
    }

    public function deactivate(DeactivateCompanyRequest $request, Company $company): PlatformCompanyDetailResource
    {
        return $this->detail($this->companies->setActive($company, false, $request->user(), $request->validated('reason')));
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $created = $this->companies->create($request->validated(), $request->user());

        return $this->detail($created->company)->response()->setStatusCode(201);
    }

    /** HU-2.3: nuevo enlace para el administrador; el anterior deja de valer. */
    public function resendAdminInvitation(Request $request, Company $company, InvitationService $invitations, TenantContext $tenant): PlatformCompanyDetailResource
    {
        $hasAdmin = CompanyMembership::withoutTenancy()->where('company_id', $company->id)->where('role', CompanyRole::CompanyAdmin)->exists();
        $invitation = Invitation::withoutTenancy()->where('company_id', $company->id)
            ->where('role', CompanyRole::CompanyAdmin)->whereNull('accepted_at')->latest('id')->first();

        if ($hasAdmin) {
            throw ValidationException::withMessages(['invitation' => 'El administrador de esta empresa ya aceptó la invitación.']);
        }
        if ($invitation === null) {
            throw ValidationException::withMessages(['invitation' => 'Esta empresa no tiene una invitación de administrador pendiente.']);
        }

        $tenant->run($company, fn () => $invitations->resend($invitation, $request->user()));

        return $this->detail($company);
    }

    private function detail(Company $company): PlatformCompanyDetailResource
    {
        $fresh = $this->summaries->withCounts(Company::query()->select('companies.*'))
            ->with('mainEstablishment.district')->findOrFail($company->id);

        return PlatformCompanyDetailResource::make($fresh);
    }
}
