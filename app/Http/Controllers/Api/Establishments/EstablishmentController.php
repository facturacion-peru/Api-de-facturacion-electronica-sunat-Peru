<?php

namespace App\Http\Controllers\Api\Establishments;

use App\Enums\CompanyRole;
use App\Establishments\EstablishmentAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Establishments\IndexEstablishmentRequest;
use App\Http\Requests\Establishments\StoreEstablishmentRequest;
use App\Http\Requests\Establishments\UpdateEstablishmentRequest;
use App\Http\Requests\Establishments\UpdateUserEstablishmentsRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\CompanyUserResource;
use App\Http\Resources\EstablishmentResource;
use App\Models\Establishment;
use App\Models\User;
use App\Services\EstablishmentService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/** Locales de la empresa (spec 017). Listar: todos; crear, editar y asignar: administrador. */
class EstablishmentController extends Controller
{
    public function __construct(private EstablishmentService $establishments) {}

    public function index(IndexEstablishmentRequest $request, EstablishmentAccess $access): ApiCollection
    {
        $user = $request->user();
        $ids = $request->boolean('include_inactive') && $user->hasCompanyRole(CompanyRole::CompanyAdmin)
            ? $access->readable($user)
            : $access->allowed($user)->pluck('id')->all();

        return EstablishmentResource::collection(
            Establishment::with('district')->withExists('salesDocuments as code_locked')->whereIn('id', $ids)->orderBy('code')->get()
        );
    }

    public function store(StoreEstablishmentRequest $request, TenantContext $tenant): JsonResponse
    {
        $establishment = $this->establishments->create($tenant->company(), $request->validated(), $request->user());

        return EstablishmentResource::make($establishment->load('district'))->response()->setStatusCode(201);
    }

    public function update(UpdateEstablishmentRequest $request, Establishment $establishment): EstablishmentResource
    {
        return EstablishmentResource::make($this->establishments->update($establishment, $request->validated(), $request->user())->load('district'));
    }

    /** {user} se resuelve solo entre los miembros de la empresa (AppServiceProvider). */
    public function assign(UpdateUserEstablishmentsRequest $request, User $user): CompanyUserResource
    {
        $ids = array_map('intval', $request->validated('establishment_ids'));

        return CompanyUserResource::make($this->establishments->assign($user, $ids, $request->user()));
    }
}
