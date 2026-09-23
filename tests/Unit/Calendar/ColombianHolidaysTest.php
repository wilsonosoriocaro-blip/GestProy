<?php

namespace Tests\Unit\Calendar;

use App\Support\Calendar\ColombianHolidays;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ColombianHolidaysTest extends TestCase
{
    /**
     * @return array<string, array{int, string}>
     */
    public static function easterDates(): array
    {
        return [
            '2024' => [2024, '2024-03-31'],
            '2025' => [2025, '2025-04-20'],
            '2026' => [2026, '2026-04-05'],
            '2027' => [2027, '2027-03-28'],
        ];
    }

    #[DataProvider('easterDates')]
    public function test_easter_sunday_is_calculated(int $year, string $expected): void
    {
        $this->assertSame($expected, ColombianHolidays::easterSunday($year)->toDateString());
    }

    public function test_2026_holidays_match_the_official_calendar(): void
    {
        $this->assertSame([
            '2026-01-01', '2026-01-12', '2026-03-23', '2026-04-02', '2026-04-03',
            '2026-05-01', '2026-05-18', '2026-06-08', '2026-06-15', '2026-06-29',
            '2026-07-20', '2026-08-07', '2026-08-17', '2026-10-12', '2026-11-02',
            '2026-11-16', '2026-12-08', '2026-12-25',
        ], array_keys(ColombianHolidays::forYear(2026)));
    }

    public function test_holidays_falling_on_the_same_monday_are_counted_once(): void
    {
        // In 2025 San Pedro y San Pablo (Jun 29, Sunday) and Sagrado Corazón both move to Jun 30.
        $holidays = ColombianHolidays::forYear(2025);

        $this->assertCount(17, $holidays);
        $this->assertArrayHasKey('2025-06-30', $holidays);
    }

    public function test_is_holiday(): void
    {
        $this->assertTrue(ColombianHolidays::isHoliday(CarbonImmutable::parse('2026-10-12')));
        $this->assertFalse(ColombianHolidays::isHoliday(CarbonImmutable::parse('2026-10-13')));
    }
}
