<?php

namespace App\Enums;

/**
 * Behaviour of a project status. Each row of project_statuses maps to one
 * kind; several statuses may share the same kind.
 */
enum ProjectStatusKind: string
{
    case Planned = 'planned';
    case Active = 'active';
    case Paused = 'paused';
    case AtRisk = 'at_risk';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function lifecycle(): Lifecycle
    {
        return match ($this) {
            self::Planned => Lifecycle::Planned,
            self::Active, self::AtRisk => Lifecycle::Open,
            self::Paused => Lifecycle::Paused,
            self::Completed => Lifecycle::Completed,
            self::Cancelled => Lifecycle::Cancelled,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planeado',
            self::Active => 'En ejecución',
            self::Paused => 'En pausa',
            self::AtRisk => 'En riesgo',
            self::Completed => 'Finalizado',
            self::Cancelled => 'Cancelado',
        };
    }
}
