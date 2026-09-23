<?php

namespace App\Data;

use App\Enums\ScheduleHealth;

/**
 * Schedule metrics of a project or task at a given date. Every day count is
 * in business days (Colombian calendar). Null means "not computable with the
 * data available" (for example, no start date), never zero.
 */
final readonly class ScheduleSnapshot
{
    public function __construct(
        public ScheduleHealth $health,
        /** Planned duration, start and due date included. */
        public ?int $totalDays,
        /** Days from the start date up to yesterday, capped at the due date. */
        public ?int $elapsedDays,
        /** Days from today up to the due date, both included. */
        public ?int $remainingDays,
        /** Days past the due date (for completed items: days finished late). */
        public int $overdueDays,
        /** Share of the planned duration already consumed, 0-100. */
        public ?int $timeConsumed,
        /** Progress a linear plan would have today, 0-100. */
        public ?int $expectedProgress,
        public int $progress,
        /** Real minus expected progress. Negative means behind. */
        public ?int $progressGap,
    ) {}

    public function isOverdue(): bool
    {
        return $this->health === ScheduleHealth::Overdue;
    }

    public function needsAttention(): bool
    {
        return $this->health->needsAttention();
    }
}
