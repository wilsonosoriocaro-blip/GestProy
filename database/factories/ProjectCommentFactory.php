<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectComment>
 */
class ProjectCommentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'task_id' => null,
            'user_id' => User::factory(),
            'body' => fake()->paragraph(),
            'is_highlighted' => false,
        ];
    }
}
