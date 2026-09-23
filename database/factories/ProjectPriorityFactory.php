<?php

namespace Database\Factories;

use App\Models\ProjectPriority;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProjectPriority>
 */
class ProjectPriorityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Prioridad '.Str::upper(Str::random(3)),
            'slug' => 'priority-'.Str::lower(Str::random(8)),
            'level' => fake()->unique()->numberBetween(100, 30000),
            'color' => 'zinc',
            'is_default' => false,
            'is_active' => true,
        ];
    }
}
