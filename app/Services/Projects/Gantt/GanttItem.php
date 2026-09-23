<?php

namespace App\Services\Projects\Gantt;

use App\Enums\ScheduleHealth;
use Carbon\CarbonImmutable;

/**
 * One row of a timeline: a project or a task.
 */
final readonly class GanttItem
{
    /**
     * @param  list<string>  $dependsOn  Keys of the items this one waits for (finish-to-start).
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $sublabel,
        public ?string $href,
        public ?CarbonImmutable $start,
        public ?CarbonImmutable $end,
        public int $progress,
        public ScheduleHealth $health,
        public bool $isOpen,
        public array $dependsOn = [],
    ) {}
}
