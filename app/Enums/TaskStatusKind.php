<?php

namespace App\Enums;

/**
 * Behaviour of a task status. Each row of project_task_statuses maps to one kind.
 */
enum TaskStatusKind: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Blocked = 'blocked';
    case InReview = 'in_review';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function lifecycle(): Lifecycle
    {
        return match ($this) {
            self::Pending => Lifecycle::Planned,
            // A blocked task keeps consuming calendar time, so it is open for scheduling purposes.
            self::InProgress, self::Blocked, self::InReview => Lifecycle::Open,
            self::Completed => Lifecycle::Completed,
            self::Cancelled => Lifecycle::Cancelled,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::InProgress => 'En ejecución',
            self::Blocked => 'Bloqueada',
            self::InReview => 'En revisión',
            self::Completed => 'Finalizada',
            self::Cancelled => 'Cancelada',
        };
    }
}
