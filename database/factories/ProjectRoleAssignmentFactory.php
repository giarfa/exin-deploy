<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectRoleAssignment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectRoleAssignment>
 */
class ProjectRoleAssignmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'role_id' => Role::factory(),
            'user_id' => User::factory()->member(),
        ];
    }
}
