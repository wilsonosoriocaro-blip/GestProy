<?php

namespace Database\Factories;

use App\Enums\ProjectStatusKind;
use App\Models\ProjectStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProjectStatus>
 */
class ProjectStatusFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return $this->forKind(ProjectStatusKind::Active);
    }

    public function kind(ProjectStatusKind $kind): static
    {
        return $this->state(fn () => $this->forKind($kind));
    }

    /**
     * @return array<string, mixed>
     */
    private function forKind(ProjectStatusKind $kind): array
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
