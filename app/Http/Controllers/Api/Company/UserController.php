<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\UpdateCompanyUserRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\CompanyUserResource;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\UserService;

/** Usuarios de la propia empresa (HU-3). Rutas con role:company_admin. */
class UserController extends Controller
{
    public function __construct(private UserService $users) {}

    public function index(): ApiCollection
    {
        $users = CompanyMembership::with('user')->get()
            ->map(fn (CompanyMembership $membership) => $membership->user->setRelation('membership', $membership))
            ->sortBy('name')
            ->values();

        return CompanyUserResource::collection($users);
    }

    /** {user} se resuelve solo entre los miembros de la empresa (AppServiceProvider). */
    public function update(UpdateCompanyUserRequest $request, User $user): CompanyUserResource
    {
        return CompanyUserResource::make($this->users->update($user, $request->validated(), $request->user()));
    }
}
