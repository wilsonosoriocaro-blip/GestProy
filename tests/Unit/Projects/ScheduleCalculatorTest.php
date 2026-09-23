<?php

namespace Tests\Unit\Projects;

use App\Enums\Lifecycle;
use App\Enums\ScheduleHealth;
use App\Services\Projects\ScheduleCalculator;
use App\Support\Calendar\BusinessCalendar;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Reference "today" is Wednesday 2026-09-23. All counts are business days.
 */
class ScheduleCalculatorTest extends TestCase
{
    private ScheduleCalculator $calculator;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new ScheduleCalculator(new BusinessCalendar, dueSoonDays: 5, behindTolerance: 15);
        $this->today = CarbonImmutable::parse('2026-09-23');
    }

    private function date(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }

    public function test_active_project_within_plan(): void
    {
        $snapshot = $this->calculator->calculate($this->date('2026-09-07'), $this->date('2026-10-02'), null, 55, Lifecycle::Open, $this->today);

        $this->assertSame(ScheduleHealth::OnTrack, $snapshot->health);
        $this->assertSame(20, $snapshot->totalDays);
        $this->assertSame(12, $snapshot->elapsedDays);
        $this->assertSame(8, $snapshot->remainingDays);
        $this->assertSame(0, $snapshot->overdueDays);
        $this->assertSame(60, $snapshot->timeConsumed);
        $this->assertSame(60, $snapshot->expectedProgress);
        $this->assertSame(-5, $snapshot->progressGap);
    }

    public function test_low_progress_against_elapsed_time_is_behind_schedule(): void
    {
        $snapshot = $this->calculator->calculate($this->date('2026-09-07'), $this->date('2026-10-02'), null, 30, Lifecycle::Open, $this->today);

        $this->assertSame(ScheduleHealth::BehindSchedule, $snapshot->health);
        $this->assertSame(-30, $snapshot->progressGap);
        $this->assertTrue($snapshot->needsAttention());
    }

    public function test_project_close_to_its_due_date_is_due_soon(): void
    {
        $snapshot = $this->calculator->calculate($this->date('2026-09-07'), $this->date('2026-09-29'), null, 70, Lifecycle::Open, $this->today);

        $this->assertSame(ScheduleHealth::DueSoon, $snapshot->health);
        $this->assertSame(5, $snapshot->remainingDays);
    }

    public function test_item_past_its_due_date_is_overdue_and_counts_delay(): void
    {
        $snapshot = $this->calculator->calculate($this->date('2026-09-07'), $this->date('2026-09-18'), null, 90, Lifecycle::Open, $this->today);

        $this->assertSame(ScheduleHealth::Overdue, $snapshot->health);
        $this->assertSame(3, $snapshot->overdueDays);
        $this->assertSame(0, $snapshot->remainingDays);
        $this->assertSame(100, $snapshot->timeConsumed);
        $this->assertSame(100, $snapshot->expectedProgress);
    }

    public function test_project_not_started_yet(): void
    {
        $snapshot = $this->calculator->calculate($this->date('2026-10-01'), $this->date('2026-10-30'), null, 0, Lifecycle::Planned, $this->today);

        $this->assertSame(ScheduleHealth::NotStarted, $snapshot->health);
        $this->assertSame(21, $snapshot->totalDays); // Oct 12 is a holiday
        $this->assertSame(0, $snapshot->elapsedDays);
        $this->assertSame(21, $snapshot->remainingDays);
        $this->assertSame(0, $snapshot->expectedProgress);
    }

    public function test_planned_item_whose_start_date_passed_is_behind(): void
    {
        $snapshot = $this->calculator->calculate($this->date('2026-09-07'), $this->date('2026-10-02'), null, 0, Lifecycle::Planned, $this->today);

        $this->assertSame(ScheduleHealth::BehindSchedule, $snapshot->health);
    }

    public function test_completed_on_time(): void
    {
        $snapshot = $this->calculator->calculate($this->date('2026-09-07'), $this->date('2026-09-11'), $this->date('2026-09-10'), 100, Lifecycle::Completed, $this->today);

        $this->assertSame(ScheduleHealth::Completed, $snapshot->health);
        $this->assertSame(0, $snapshot->overdueDays);
        $this->assertSame(0, $snapshot->remainingDays);
        $this->assertSame(4, $snapshot->elapsedDays);
    }

    public function test_completed_after_due_date_reports_days_late(): void
    {
        $snapshot = $this->calculator->calculate($this->date('2026-09-07'), $this->date('2026-09-11'), $this->date('2026-09-15'), 100, Lifecycle::Completed, $this->today);

        $this->assertSame(ScheduleHealth::CompletedLate, $snapshot->health);
        $this->assertSame(2, $snapshot->overdueDays);
    }

    public function test_cancelled_items_are_never_overdue(): void
    {
        $snapshot = $this->calculator->calculate($this->date('2026-09-01'), $this->date('2026-09-10'), null, 20, Lifecycle::Cancelled, $this->today);

        $this->assertSame(ScheduleHealth::Cancelled, $snapshot->health);
        $this->assertSame(0, $snapshot->overdueDays);
        $this->assertNull($snapshot->remainingDays);
    }

    public function test_missing_due_date_returns_no_metrics(): void
    {
        $snapshot = $this->calculator->calculate($this->date('2026-09-01'), null, null, 20, Lifecycle::Open, $this->today);

        $this->assertSame(ScheduleHealth::NoDates, $snapshot->health);
        $this->assertNull($snapshot->totalDays);
        $this->assertNull($snapshot->remainingDays);
        $this->assertNull($snapshot->expectedProgress);
    }

    public function test_missing_start_date_still_tracks_remaining_days(): void
    {
        $snapshot = $this->calculator->calculate(null, $this->date('2026-10-30'), null, 10, Lifecycle::Open, $this->today);

        $this->assertSame(ScheduleHealth::OnTrack, $snapshot->health);
        $this->assertSame(27, $snapshot->remainingDays); // Oct 12 is a holiday
        $this->assertNull($snapshot->elapsedDays);
        $this->assertNull($snapshot->expectedProgress);
    }

    public function test_paused_item_is_paused_until_its_due_date_passes(): void
    {
        $paused = $this->calculator->calculate($this->date('2026-09-07'), $this->date('2026-10-02'), null, 10, Lifecycle::Paused, $this->today);
        $pausedAndLate = $this->calculator->calculate($this->date('2026-09-07'), $this->date('2026-09-18'), null, 10, Lifecycle::Paused, $this->today);

        $this->assertSame(ScheduleHealth::Paused, $paused->health);
        $this->assertSame(ScheduleHealth::Overdue, $pausedAndLate->health);
    }

    public function test_plan_made_only_of_non_working_days(): void
    {
        $snapshot = $this->calculator->calculate($this->date('2026-09-26'), $this->date('2026-09-27'), null, 0, Lifecycle::Open, $this->date('2026-09-28'));

        $this->assertSame(0, $snapshot->totalDays);
        $this->assertSame(ScheduleHealth::Overdue, $snapshot->health);
        $this->assertSame(100, $snapshot->timeConsumed);
        $this->assertSame(1, $snapshot->overdueDays);
    }
}
