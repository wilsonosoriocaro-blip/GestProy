<?php

namespace App\Services\Projects\Gantt;

/**
 * Palette used to tell projects apart when several of them share one
 * timeline (the portfolio view's per-person workload). Blue is the
 * default, single-project look everywhere else, so it stays out of this
 * cycle to avoid implying "this one is a project you own".
 */
enum GanttColor: string
{
    case Violet = 'violet';
    case Amber = 'amber';
    case Emerald = 'emerald';
    case Cyan = 'cyan';
    case Fuchsia = 'fuchsia';
    case Orange = 'orange';
    case Teal = 'teal';
    case Rose = 'rose';

    public static function cycle(int $index): self
    {
        $cases = self::cases();

        return $cases[$index % count($cases)];
    }
}
