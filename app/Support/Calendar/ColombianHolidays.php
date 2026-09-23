<?php

namespace App\Support\Calendar;

use Carbon\CarbonImmutable;

/**
 * Colombian public holidays.
 *
 * Rules (Ley 51 de 1983, "Ley Emiliani"):
 *  - Fixed holidays that are never moved.
 *  - Holidays moved to the following Monday when they do not fall on one.
 *  - Holidays relative to Easter Sunday; Ascension, Corpus Christi and
 *    Sacred Heart are observed on the Monday after the religious date.
 */
class ColombianHolidays
{
    /** @var list<array{int, int}> [month, day] */
    private const FIXED = [
        [1, 1],   // Año Nuevo
        [5, 1],   // Día del Trabajo
        [7, 20],  // Grito de Independencia
        [8, 7],   // Batalla de Boyacá
        [12, 8],  // Inmaculada Concepción
        [12, 25], // Navidad
    ];

    /** @var list<array{int, int}> [month, day] */
    private const MOVED_TO_MONDAY = [
        [1, 6],   // Reyes Magos
        [3, 19],  // San José
        [6, 29],  // San Pedro y San Pablo
        [8, 15],  // Asunción de la Virgen
        [10, 12], // Día de la Raza
        [11, 1],  // Todos los Santos
        [11, 11], // Independencia de Cartagena
    ];

    /** Offsets in days from Easter Sunday, already moved to Monday where the law requires it. */
    private const EASTER_OFFSETS = [
        -3, // Jueves Santo
        -2, // Viernes Santo
        43, // Ascensión del Señor (jueves +39, trasladado al lunes)
        64, // Corpus Christi (jueves +60, trasladado al lunes)
        71, // Sagrado Corazón (viernes +68, trasladado al lunes)
    ];

    /** @var array<int, array<string, true>> */
    private static array $cache = [];

    /**
     * Holiday dates of the given year as a set keyed by Y-m-d.
     *
     * @return array<string, true>
     */
    public static function forYear(int $year): array
    {
        if (isset(self::$cache[$year])) {
            return self::$cache[$year];
        }

        $dates = [];

        foreach (self::FIXED as [$month, $day]) {
            $dates[] = CarbonImmutable::create($year, $month, $day);
        }

        foreach (self::MOVED_TO_MONDAY as [$month, $day]) {
            $date = CarbonImmutable::create($year, $month, $day);
            $dates[] = $date->isMonday() ? $date : $date->next(CarbonImmutable::MONDAY);
        }

        $easter = self::easterSunday($year);

        foreach (self::EASTER_OFFSETS as $offset) {
            $dates[] = $easter->addDays($offset);
        }

        $set = [];

        foreach ($dates as $date) {
            $set[$date->toDateString()] = true;
        }

        ksort($set);

        return self::$cache[$year] = $set;
    }

    public static function isHoliday(CarbonImmutable $date): bool
    {
        return isset(self::forYear($date->year)[$date->toDateString()]);
    }

    /**
     * Easter Sunday for the Gregorian calendar (Anonymous Gregorian algorithm).
     * Implemented here so the module does not depend on ext-calendar.
     */
    public static function easterSunday(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day);
    }
}
