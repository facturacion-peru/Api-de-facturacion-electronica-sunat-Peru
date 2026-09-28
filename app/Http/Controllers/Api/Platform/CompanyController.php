<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Services\CompanyService;
use Illuminate\Http\JsonResponse;

/** Gestión de empresas por el administrador de la plataforma. */
class CompanyController extends Controller
{
    public function __construct(private CompanyService $companies) {}

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $created = $this->companies->create($request->validated(), $request->user());

        return CompanyResource::make($created->company)->response()->setStatusCode(201);
    }
}
