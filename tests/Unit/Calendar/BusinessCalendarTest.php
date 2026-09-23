<?php

namespace Tests\Unit\Calendar;

use App\Support\Calendar\BusinessCalendar;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class BusinessCalendarTest extends TestCase
{
    private BusinessCalendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calendar = new BusinessCalendar([6, 7], ['2026-12-24']);
    }

    public function test_weekdays_are_business_days(): void
    {
        $this->assertTrue($this->calendar->isBusinessDay(CarbonImmutable::parse('2026-09-23')));
    }

    public function test_weekends_holidays_and_extra_days_are_not_business_days(): void
    {
        $this->assertFalse($this->calendar->isBusinessDay(CarbonImmutable::parse('2026-09-26')));
        $this->assertFalse($this->calendar->isBusinessDay(CarbonImmutable::parse('2026-09-27')));
        $this->assertFalse($this->calendar->isBusinessDay(CarbonImmutable::parse('2026-10-12')));
        $this->assertFalse($this->calendar->isBusinessDay(CarbonImmutable::parse('2026-12-24')));
    }

    public function test_count_between_includes_both_ends_and_skips_non_working_days(): void
    {
        // Mon Sep 7 to Fri Oct 2, 2026: four full weeks without holidays.
        $this->assertSame(20, $this->calendar->countBetween(CarbonImmutable::parse('2026-09-07'), CarbonImmutable::parse('2026-10-02')));

        // Fri Oct 9 to Tue Oct 13: the weekend and Monday Oct 12 (holiday) are skipped.
        $this->assertSame(2, $this->calendar->countBetween(CarbonImmutable::parse('2026-10-09'), CarbonImmutable::parse('2026-10-13')));
    }

    public function test_count_between_is_zero_when_the_range_is_reversed(): void
    {
        $this->assertSame(0, $this->calendar->countBetween(CarbonImmutable::parse('2026-09-23'), CarbonImmutable::parse('2026-09-22')));
    }

    public function test_add_business_days_skips_holidays(): void
    {
        $this->assertSame('2026-10-13', $this->calendar->addBusinessDays(CarbonImmutable::parse('2026-10-09'), 1)->toDateString());
        $this->assertSame('2026-09-28', $this->calendar->addBusinessDays(CarbonImmutable::parse('2026-09-26'), 0)->toDateString());
    }

    public function test_fast_count_matches_a_day_by_day_count(): void
    {
        mt_srand(2026);

        for ($i = 0; $i < 300; $i++) {
            $from = CarbonImmutable::parse('2025-01-01')->addDays(mt_rand(0, 900));
            $to = $from->addDays(mt_rand(-3, 400));

            $expected = 0;
            for ($day = $from; $day->lte($to); $day = $day->addDay()) {
                $expected += $this->calendar->isBusinessDay($day) ? 1 : 0;
            }

            $this->assertSame($expected, $this->calendar->countBetween($from, $to), "{$from->toDateString()} → {$to->toDateString()}");
        }
    }

    public function test_sub_business_days(): void
    {
        // Tue Oct 13, 2026 minus one business day skips Monday Oct 12 (holiday) and the weekend.
        $this->assertSame('2026-10-09', $this->calendar->subBusinessDays(CarbonImmutable::parse('2026-10-13'), 1)->toDateString());
        $this->assertSame('2026-10-13', $this->calendar->subBusinessDays(CarbonImmutable::parse('2026-10-13'), 0)->toDateString());
    }
}
