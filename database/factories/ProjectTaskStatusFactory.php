<?php

namespace Database\Factories;

use App\Enums\TaskStatusKind;
use App\Models\ProjectTaskStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProjectTaskStatus>
 */
class ProjectTaskStatusFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return $this->forKind(TaskStatusKind::InProgress);
    }

    public function kind(TaskStatusKind $kind): static
    {
        return $this->state(fn () => $this->forKind($kind));
    }

    /**
     * @return array<string, mixed>
     */
    private function forKind(TaskStatusKind $kind): array
    {
        return [
            'name' => $kind->label(),
            'slug' => $kind->value.'-'.Str::lower(Str::random(6)),
            'kind' => $kind,
            'color' => 'zinc',
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
