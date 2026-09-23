<?php

namespace Database\Factories;

use App\Enums\TaskStatusKind;
use App\Models\Project;
use App\Models\ProjectPriority;
use App\Models\ProjectTask;
use App\Models\ProjectTaskStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectTask>
 */
class ProjectTaskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = today()->subDays(fake()->numberBetween(0, 20));

        return [
            'project_id' => Project::factory(),
            'parent_id' => null,
            'name' => rtrim(fake()->sentence(3), '.'),
            'description' => fake()->sentence(),
            'assignee_id' => null,
            'status_id' => ProjectTaskStatus::factory(),
            'priority_id' => ProjectPriority::factory(),
            'start_date' => $start,
            'due_date' => $start->addDays(fake()->numberBetween(5, 30)),
            'completed_at' => null,
            'progress' => 0,
            'weight' => 1,
            'sort_order' => 0,
        ];
    }

    public function withStatus(TaskStatusKind $kind): static
    {
        return $this->state(fn () => [
            'status_id' => ProjectTaskStatus::factory()->kind($kind),
        ]);
    }
}
