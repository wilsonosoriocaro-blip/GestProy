<?php

namespace App\Enums;

/**
 * How a project's progress is obtained. Exactly one source is authoritative
 * at a time, so manual and calculated values never compete.
 */
enum ProgressMode: string
{
    /** Progress is typed by the project owner or leader. */
    case Manual = 'manual';

    /** Progress is the weighted average of the project's top-level tasks. */
    case Tasks = 'tasks';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Tasks => 'Calculado por tareas',
        };
    }
}
