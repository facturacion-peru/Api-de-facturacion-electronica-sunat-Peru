<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AcceptInvitationRequest;
use App\Http\Resources\PublicInvitationResource;
use App\Http\Resources\SessionResource;
use App\Services\InvitationService;
use Illuminate\Http\JsonResponse;

/** Flujo público: ver y aceptar una invitación (HU-2.1, HU-2.2). */
class InvitationAcceptanceController extends Controller
{
    public function __construct(private InvitationService $invitations) {}

    public function show(string $token): PublicInvitationResource
    {
        $invitation = $this->invitations->findByToken($token) ?? abort(404);
        $this->invitations->ensureUsable($invitation);

        return PublicInvitationResource::make($invitation);
    }

    public function accept(AcceptInvitationRequest $request, string $token): JsonResponse
    {
        $invitation = $this->invitations->findByToken($token) ?? abort(404);

        $user = $this->invitations->accept($invitation, $request->validated('name'), $request->validated('password'));
        $plainToken = $user->createToken('app')->plainTextToken;

        return (new SessionResource($user->load('membership.company'), $plainToken))
            ->response()
            ->setStatusCode(201);
    }
}
