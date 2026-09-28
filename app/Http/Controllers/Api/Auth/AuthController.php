<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\SessionResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private AuthService $auth) {}

    public function login(LoginRequest $request): SessionResource
    {
        $user = $this->auth->attempt($request->validated('email'), $request->validated('password'), (string) $request->ip());

        return new SessionResource($user, $user->createToken('app')->plainTextToken);
    }

    public function me(Request $request): SessionResource
    {
        return new SessionResource($request->user()->load('membership.company'));
    }

    /** Revoca solo el token de este dispositivo. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['success' => true, 'message' => 'Sesión cerrada.']);
    }
}
