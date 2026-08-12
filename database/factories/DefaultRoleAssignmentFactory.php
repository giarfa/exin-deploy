<?php

namespace Database\Factories;

use App\Models\DefaultRoleAssignment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DefaultRoleAssignment>
 */
class DefaultRoleAssignmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'role_id' => Role::factory(),
            'user_id' => User::factory()->member(),
        ];
    }
}
