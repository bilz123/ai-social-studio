<?php

namespace Database\Factories;

use App\Models\User;
use App\Support\Auth\RoleCatalog;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            // Plain text: the model's "hashed" cast hashes it on save.
            'password' => 'Str0ng-Passw0rd!',
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (): array => ['email_verified_at' => null]);
    }

    public function member(): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole(RoleCatalog::MEMBER));
    }

    public function admin(): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole(RoleCatalog::ADMIN));
    }
}
