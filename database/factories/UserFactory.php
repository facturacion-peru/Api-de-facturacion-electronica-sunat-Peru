<?php

namespace Database\Factories;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
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
