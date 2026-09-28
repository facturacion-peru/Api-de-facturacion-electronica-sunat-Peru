<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Inicio de sesión (HU-2.3, HU-2.4, RF-012).
 *
 * El mensaje de error es idéntico exista o no el correo. El estado
 * "desactivado" solo se revela con la contraseña correcta.
 */
class AuthService
{
    public const MAX_ATTEMPTS = 5;

    public const LOCKOUT_SECONDS = 15 * 60;

    public function __construct(private AuditLogger $audit) {}

    public function attempt(string $email, string $password, string $ip): User
    {
        $key = 'login:'.$email.'|'.$ip;

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw new ThrottleRequestsException(headers: ['Retry-After' => RateLimiter::availableIn($key)]);
        }

        $user = User::with('membership.company')->where('email', $email)->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            RateLimiter::hit($key, self::LOCKOUT_SECONDS);

            $this->audit->record('auth.login_failed', $user, ['email' => $email],
                company: $user?->membership?->company);

            throw ValidationException::withMessages(['email' => 'Las credenciales no son correctas.']);
        }

        RateLimiter::clear($key);

        if (! $this->canSignIn($user)) {
            throw ValidationException::withMessages(['email' => 'Tu cuenta o tu empresa están desactivadas.']);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return $user;
    }

    private function canSignIn(User $user): bool
    {
        if (! $user->active) {
            return false;
        }

        if ($user->isPlatformAdmin()) {
            return true;
        }

        $membership = $user->membership;

        return $membership !== null && $membership->active && $membership->company->active;
    }
}
