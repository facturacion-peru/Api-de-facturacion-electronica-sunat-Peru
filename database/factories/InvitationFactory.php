<?php

namespace Database\Factories;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Invitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => CompanyRole::Seller,
            'token_hash' => Invitation::hashToken(Str::random(64)),
            'expires_at' => now()->addHours(72),
        ];
    }

    /** Fija el token en claro para poder usarlo en la prueba. */
    public function withToken(string $token): static
    {
        return $this->state(fn () => ['token_hash' => Invitation::hashToken($token)]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }

    public function accepted(): static
    {
        return $this->state(fn () => ['accepted_at' => now()->subHour()]);
    }
}
