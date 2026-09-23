<?php

namespace App\Support\Calendar;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Business-day arithmetic on the Colombian calendar: weekends, national
 * holidays and any extra non-working day configured in business_calendar.
 */
class BusinessCalendar
{
    /** @var array<int, true> */
    private array $weekendDays;

    /** @var array<string, true> */
    private array $extraNonWorkingDays;

    /**
     * @param  list<int>  $weekendDays  ISO-8601 day numbers
     * @param  list<string>  $extraNonWorkingDays  Y-m-d dates
     */
    public function __construct(array $weekendDays = [6, 7], array $extraNonWorkingDays = [])
    {
        $this->weekendDays = array_fill_keys($weekendDays, true);
        $this->extraNonWorkingDays = array_fill_keys($extraNonWorkingDays, true);
    }

    public function isBusinessDay(CarbonInterface $date): bool
    {
        $date = CarbonImmutable::instance($date)->startOfDay();

        return ! isset($this->weekendDays[$date->dayOfWeekIso])
            && ! isset($this->extraNonWorkingDays[$date->toDateString()])
            && ! ColombianHolidays::isHoliday($date);
    }

    /**
     * Business days between both dates, both ends included. Zero when $from is after $to.
     *
     * Runs in constant time per year of range: whole weeks are counted
     * arithmetically and only holidays inside the range are subtracted, so
     * dashboards can compute it for every project on each request.
     */
    public function countBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        $start = CarbonImmutable::instance($from)->startOfDay();
        $end = CarbonImmutable::instance($to)->startOfDay();

        if ($start->gt($end)) {
            return 0;
        }

        $days = (int) $start->diffInDays($end) + 1;
        $count = intdiv($days, 7) * (7 - count($this->weekendDays));

        // Leftover days after the whole weeks.
        for ($cursor = $start->addDays($days - $days % 7); $cursor->lte($end); $cursor = $cursor->addDay()) {
            if (! isset($this->weekendDays[$cursor->dayOfWeekIso])) {
                $count++;
            }
        }

        return $count - $this->nonWorkingWeekdaysBetween($start, $end);
    }

    /**
     * Holidays and extra days off that fall on a weekday inside the range.
     */
    private function nonWorkingWeekdaysBetween(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $from = $start->toDateString();
        $to = $end->toDateString();
        $dates = $this->extraNonWorkingDays;

        for ($year = $start->year; $year <= $end->year; $year++) {
            $dates += ColombianHolidays::forYear($year);
        }

        $count = 0;

        foreach (array_keys($dates) as $date) {
            if ($date >= $from && $date <= $to && ! isset($this->weekendDays[CarbonImmutable::parse($date)->dayOfWeekIso])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * The date that is $days business days before $date (the same date with zero).
     */
    public function subBusinessDays(CarbonInterface $date, int $days): CarbonImmutable
    {
        $cursor = CarbonImmutable::instance($date)->startOfDay();

        while ($days > 0) {
            $cursor = $cursor->subDay();

            if ($this->isBusinessDay($cursor)) {
                $days--;
            }
        }

        return $cursor;
    }

    /**
     * The date that is $days business days after $date. With zero, the same
     * date when it is a business day, otherwise the next business day.
     */
    public function addBusinessDays(CarbonInterface $date, int $days): CarbonImmutable
    {
        $cursor = CarbonImmutable::instance($date)->startOfDay();

        while (! $this->isBusinessDay($cursor)) {
            $cursor = $cursor->addDay();
        }

        while ($days > 0) {
            $cursor = $cursor->addDay();

            if ($this->isBusinessDay($cursor)) {
                $days--;
            }
        }

        return $cursor;
    }
}
