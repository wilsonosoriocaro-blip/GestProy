<?php

namespace App\Enums;

/**
 * Computed schedule condition of a project or task. It is never stored:
 * it is derived from dates, progress and lifecycle on every read.
 * Each case carries a text label and an icon so the UI never relies on
 * color alone.
 */
enum ScheduleHealth: string
{
    case NoDates = 'no_dates';
    case NotStarted = 'not_started';
    case OnTrack = 'on_track';
    case DueSoon = 'due_soon';
    case BehindSchedule = 'behind_schedule';
    case Overdue = 'overdue';
    case Paused = 'paused';
    case Completed = 'completed';
    case CompletedLate = 'completed_late';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::NoDates => 'Sin fechas',
            self::NotStarted => 'Por iniciar',
            self::OnTrack => 'Dentro del plazo',
            self::DueSoon => 'Próximo a vencer',
            self::BehindSchedule => 'Avance bajo',
            self::Overdue => 'Atrasado',
            self::Paused => 'En pausa',
            self::Completed => 'Finalizado a tiempo',
            self::CompletedLate => 'Finalizado con retraso',
            self::Cancelled => 'Cancelado',
        };
    }

    /** Heroicon name (available in Flux). */
    public function icon(): string
    {
        return match ($this) {
            self::NoDates => 'calendar',
            self::NotStarted => 'clock',
            self::OnTrack => 'check-circle',
            self::DueSoon => 'bell-alert',
            self::BehindSchedule => 'arrow-trending-down',
            self::Overdue => 'exclamation-triangle',
            self::Paused => 'pause-circle',
            self::Completed => 'check-badge',
            self::CompletedLate => 'check-badge',
            self::Cancelled => 'x-circle',
        };
    }

    /** Flux badge color. */
    public function color(): string
    {
        return match ($this) {
            self::NoDates, self::NotStarted, self::Cancelled => 'zinc',
            self::OnTrack, self::Completed => 'green',
            self::DueSoon => 'amber',
            self::BehindSchedule, self::CompletedLate => 'orange',
            self::Overdue => 'red',
            self::Paused => 'sky',
        };
    }

    /** Needs the leader's attention. */
    public function needsAttention(): bool
    {
        return in_array($this, [self::DueSoon, self::BehindSchedule, self::Overdue], true);
    }
}
