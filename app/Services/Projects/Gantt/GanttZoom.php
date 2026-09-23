<?php

namespace App\Services\Projects\Gantt;

/**
 * Horizontal scale of the timeline.
 */
enum GanttZoom: string
{
    case Week = 'week';
    case Month = 'month';
    case Quarter = 'quarter';

    public function pixelsPerDay(): int
    {
        return match ($this) {
            self::Week => 28,
            self::Month => 9,
            self::Quarter => 3,
        };
    }

    /** Calendar days of margin before the first and after the last date. */
    public function padding(): int
    {
        return match ($this) {
            self::Week => 3,
            self::Month => 7,
            self::Quarter => 14,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Week => 'Semanas',
            self::Month => 'Meses',
            self::Quarter => 'Trimestres',
        };
    }
}
