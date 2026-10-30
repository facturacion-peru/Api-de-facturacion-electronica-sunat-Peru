<?php

namespace Database\Factories;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Establishment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function platformAdmin(): static
    {
        return $this->state(fn () => ['is_platform_admin' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }

    /** Usuario de empresa con su pertenencia y rol. */
    public function forCompany(Company $company, CompanyRole $role = CompanyRole::Seller, bool $active = true): static
    {
        return $this->afterCreating(function (User $user) use ($company, $role, $active) {
            CompanyMembership::factory()->create([
                'company_id' => $company->id,
                'user_id' => $user->id,
                'role' => $role,
                'active' => $active,
            ]);

            // Como al aceptar una invitación (spec 017): con un solo local
            // activo, el vendedor queda en ese. La prueba de la migración crea
            // vendedores con el esquema anterior, sin locales.
            if (! Schema::hasTable('establishment_user')) {
                return;
            }

            $locals = Establishment::withoutTenancy()->where('company_id', $company->id)->where('active', true)->pluck('id');
            if ($role === CompanyRole::Seller && $locals->count() === 1) {
                $user->establishments()->attach($locals->first(), ['company_id' => $company->id]);
            }
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
