<?php

namespace App\Services\Projects;

use App\Data\ScheduleSnapshot;
use App\Enums\Lifecycle;
use App\Enums\ScheduleHealth;
use App\Support\Calendar\BusinessCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Computes schedule metrics for projects and tasks. It is pure: it receives
 * dates, progress and lifecycle and never touches the database, so it can be
 * used from models, queries, jobs and tests alike.
 */
class ScheduleCalculator
{
    public function __construct(
        private readonly BusinessCalendar $calendar,
        private readonly int $dueSoonDays = 5,
        private readonly int $behindTolerance = 15,
    ) {}

    public function calculate(
        ?CarbonInterface $startDate,
        ?CarbonInterface $dueDate,
        ?CarbonInterface $completedAt,
        int $progress,
        Lifecycle $lifecycle,
        ?CarbonInterface $today = null,
    ): ScheduleSnapshot {
        $today = CarbonImmutable::instance($today ?? CarbonImmutable::today())->startOfDay();
        $start = $startDate ? CarbonImmutable::instance($startDate)->startOfDay() : null;
        $due = $dueDate ? CarbonImmutable::instance($dueDate)->startOfDay() : null;
        $progress = max(0, min(100, $progress));

        $total = ($start && $due) ? $this->calendar->countBetween($start, $due) : null;

        if ($lifecycle === Lifecycle::Cancelled) {
            return new ScheduleSnapshot(ScheduleHealth::Cancelled, $total, null, null, 0, null, null, $progress, null);
        }

        if ($lifecycle === Lifecycle::Completed) {
            return $this->completed($start, $due, $completedAt, $total, $progress);
        }

        if ($due === null) {
            return new ScheduleSnapshot(
                $lifecycle === Lifecycle::Paused ? ScheduleHealth::Paused : ScheduleHealth::NoDates,
                null, null, null, 0, null, null, $progress, null,
            );
        }

        // Not started yet: nothing is expected, the whole plan remains.
        if ($start !== null && $today->lt($start)) {
            return new ScheduleSnapshot(
                $lifecycle === Lifecycle::Paused ? ScheduleHealth::Paused : ScheduleHealth::NotStarted,
                $total, 0, $total, 0, 0, 0, $progress, $progress,
            );
        }

        $isPastDue = $today->gt($due);
        $remaining = $isPastDue ? 0 : $this->calendar->countBetween($today, $due);
        $overdue = $isPastDue ? $this->calendar->countBetween($due->addDay(), $today) : 0;

        $elapsed = null;
        $timeConsumed = $isPastDue ? 100 : null;

        if ($start !== null) {
            $lastElapsedDay = $today->subDay()->min($due);
            $elapsed = $this->calendar->countBetween($start, $lastElapsedDay);
            $timeConsumed = $this->percentage($elapsed, (int) $total, $isPastDue);
        }

        $expected = $timeConsumed;
        $gap = $expected === null ? null : $progress - $expected;

        $health = match (true) {
            $isPastDue => ScheduleHealth::Overdue,
            $lifecycle === Lifecycle::Paused => ScheduleHealth::Paused,
            $gap !== null && -$gap > $this->behindTolerance => ScheduleHealth::BehindSchedule,
            $remaining <= $this->dueSoonDays => ScheduleHealth::DueSoon,
            default => ScheduleHealth::OnTrack,
        };

        return new ScheduleSnapshot($health, $total, $elapsed, $remaining, $overdue, $timeConsumed, $expected, $progress, $gap);
    }

    private function completed(
        ?CarbonImmutable $start,
        ?CarbonImmutable $due,
        ?CarbonInterface $completedAt,
        ?int $total,
        int $progress,
    ): ScheduleSnapshot {
        $finished = $completedAt ? CarbonImmutable::instance($completedAt)->startOfDay() : null;

        $late = ($due && $finished && $finished->gt($due))
            ? $this->calendar->countBetween($due->addDay(), $finished)
            : 0;

        $elapsed = ($start && $finished) ? $this->calendar->countBetween($start, $finished) : null;

        return new ScheduleSnapshot(
            $late > 0 ? ScheduleHealth::CompletedLate : ScheduleHealth::Completed,
            $total,
            $elapsed,
            0,
            $late,
            $total && $elapsed !== null ? min(100, (int) round($elapsed / $total * 100)) : null,
            null,
            $progress,
            null,
        );
    }

    private function percentage(int $part, int $whole, bool $isPastDue): int
    {
        // A plan made only of non-working days has no measurable duration.
        if ($whole === 0) {
            return $isPastDue ? 100 : 0;
        }

        return min(100, (int) round($part / $whole * 100));
    }
}
