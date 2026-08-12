<?php

namespace Database\Factories;

use App\Enums\UserLevel;
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
        $name = fake('it_IT')->name();

        return [
            'name' => $name,
            'email' => Str::slug($name, '.').'@'.fake()->unique()->numberBetween(1, 999999).'.gruppoexcellence.com',
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('Rilascio-2026!'),
            'level' => UserLevel::Member,
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
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
     * Amministratore: puo configurare il sistema e intervenire su qualsiasi release.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'level' => UserLevel::Admin,
        ]);
    }

    /**
     * Membro ordinario: opera solo sugli step di cui e responsabile.
     */
    public function member(): static
    {
        return $this->state(fn (array $attributes) => [
            'level' => UserLevel::Member,
        ]);
    }

    /**
     * Membro disattivato: non accede piu, ma resta leggibile nello storico.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
