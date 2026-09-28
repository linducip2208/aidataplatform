<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
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
            'role' => UserRole::Analyst->value,
            'is_active' => true,
            'last_login_at' => now(),
        ];
    }

    /**
     * Indicate that the model has the administrator role.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Admin->value,
        ]);
    }

    /**
     * Indicate that the model has the analyst role.
     */
    public function analyst(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Analyst->value,
        ]);
    }

    /**
     * Indicate that the model has the read-only viewer role.
     */
    public function viewer(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Viewer->value,
        ]);
    }

    /**
     * Indicate that the model is deactivated and cannot write.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
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

    /**
     * Indicate that the model should have a remembered token.
     */
    public function rememberToken(): static
    {
        return $this->state(fn (array $attributes) => [
            'remember_token' => Str::random(10),
        ]);
    }
}
