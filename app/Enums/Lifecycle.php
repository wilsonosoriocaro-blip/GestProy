<?php

namespace App\Enums;

/**
 * Generic lifecycle shared by project and task statuses. Schedule and
 * indicator logic only looks at this, never at a status name, so new
 * statuses can be added from the catalog without touching code.
 */
enum Lifecycle: string
{
    case Planned = 'planned';
    case Open = 'open';
    case Paused = 'paused';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function isClosed(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }
}
