<?php

namespace App\Http\Controllers\Api\Auth;

use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/** Recuperación de contraseña (HU-2.7, HU-2.8, RF-016). */
class PasswordResetController extends Controller
{
    /** La respuesta es idéntica exista o no el correo. */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $email = $request->validated('email');

        if (User::where('email', $email)->where('active', true)->exists()) {
            Password::sendResetLink(['email' => $email]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Si el correo está registrado, recibirás un enlace para restablecer tu contraseña.',
        ]);
    }

    /** Cambia la contraseña y cierra todas las sesiones del usuario. */
    public function reset(ResetPasswordRequest $request, AuditLogger $audit): JsonResponse
    {
        $status = Password::reset(
            $request->validated(),
            function (User $user, string $password) use ($audit) {
                $user->forceFill(['password' => $password])->save();
                $user->tokens()->delete();

                $audit->record('auth.password_reset', $user, company: $user->membership?->company, actor: $user);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => 'El enlace no es válido o venció. Pide uno nuevo.']);
        }

        return response()->json(['success' => true, 'message' => 'Contraseña actualizada. Ya puedes iniciar sesión.']);
    }
}
