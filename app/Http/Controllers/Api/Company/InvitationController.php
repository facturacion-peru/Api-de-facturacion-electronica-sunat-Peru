<?php

namespace App\Http\Controllers\Api\Company;

use App\Enums\CompanyRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StoreInvitationRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\InvitationResource;
use App\Models\Invitation;
use App\Services\InvitationService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Invitaciones de la propia empresa (HU-3). Rutas con role:company_admin. */
class InvitationController extends Controller
{
    public function __construct(private InvitationService $invitations) {}

    /** Pendientes (vigentes o vencidas), no las aceptadas. */
    public function index(): ApiCollection
    {
        return InvitationResource::collection(
            Invitation::with('inviter')->whereNull('accepted_at')->latest()->get()
        );
    }

    public function store(StoreInvitationRequest $request, TenantContext $tenant): JsonResponse
    {
        $issued = $this->invitations->issue(
            $tenant->company(),
            $request->validated('email'),
            CompanyRole::from($request->validated('role')),
            $request->user(),
        );

        return InvitationResource::make($issued->invitation->load('inviter'))->response()->setStatusCode(201);
    }

    public function resend(Request $request, Invitation $invitation): InvitationResource
    {
        $issued = $this->invitations->resend($invitation, $request->user());

        return InvitationResource::make($issued->invitation->load('inviter'));
    }

    public function destroy(Request $request, Invitation $invitation): JsonResponse
    {
        $this->invitations->cancel($invitation, $request->user());

        return response()->json(['success' => true, 'message' => 'Invitación cancelada.']);
    }
}
