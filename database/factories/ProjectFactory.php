<?php

namespace Database\Factories;

use App\Enums\ProgressMode;
use App\Enums\ProjectStatusKind;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectPriority;
use App\Models\ProjectStatus;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = today()->subDays(fake()->numberBetween(0, 60));

        return [
            'code' => 'TED-'.Str::upper(Str::random(8)),
            'name' => rtrim(fake()->sentence(4), '.'),
            'description' => fake()->paragraph(),
            'objective' => fake()->sentence(),
            'scope' => fake()->sentence(),
            'category_id' => ProjectCategory::factory(),
            'status_id' => ProjectStatus::factory(),
            'priority_id' => ProjectPriority::factory(),
            'owner_id' => User::factory(),
            'start_date' => $start,
            'due_date' => $start->addDays(fake()->numberBetween(30, 180)),
            'completed_at' => null,
            'progress' => 0,
            'progress_mode' => ProgressMode::Tasks,
        ];
    }

    public function withStatus(ProjectStatusKind $kind): static
    {
        return $this->state(fn () => [
            'status_id' => ProjectStatus::factory()->kind($kind),
        ]);
    }

    public function manualProgress(int $progress): static
    {
        return $this->state(fn () => [
            'progress_mode' => ProgressMode::Manual,
            'progress' => $progress,
        ]);
    }

    public function between(?CarbonInterface $start, ?CarbonInterface $due): static
    {
        return $this->state(fn () => [
            'start_date' => $start,
            'due_date' => $due,
        ]);
    }
}
