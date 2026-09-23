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
     */
    public function countBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        $cursor = CarbonImmutable::instance($from)->startOfDay();
        $end = CarbonImmutable::instance($to)->startOfDay();
        $count = 0;

        while ($cursor->lte($end)) {
            if ($this->isBusinessDay($cursor)) {
                $count++;
            }

            $cursor = $cursor->addDay();
        }

        return $count;
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
