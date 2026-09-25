<?php

namespace App\Enums;

/**
 * Coarse "is it keeping its dates?" reading of ScheduleHealth, used to
 * color task bars when the portfolio timeline is filtered to one project.
 * Colors are the fixed status steps; every use ships with icon + label.
 */
enum ScheduleCompliance: string
{
    case Meeting = 'meeting';
    case AtRisk = 'at_risk';
    case Failing = 'failing';
    case Neutral = 'neutral';

    public static function fromHealth(ScheduleHealth $health): self
    {
        return match ($health) {
            ScheduleHealth::OnTrack, ScheduleHealth::Completed => self::Meeting,
            ScheduleHealth::DueSoon, ScheduleHealth::BehindSchedule => self::AtRisk,
            ScheduleHealth::Overdue, ScheduleHealth::CompletedLate => self::Failing,
            ScheduleHealth::NotStarted, ScheduleHealth::NoDates, ScheduleHealth::Paused, ScheduleHealth::Cancelled => self::Neutral,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Meeting => 'Cumpliendo',
            self::AtRisk => 'En riesgo',
            self::Failing => 'Incumpliendo',
            self::Neutral => 'Por iniciar',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Meeting => 'Dentro del plazo o a tiempo',
            self::AtRisk => 'Próximas a vencer o avance bajo',
            self::Failing => 'Vencidas o terminadas tarde',
            self::Neutral => 'Aún no empiezan, en pausa o sin fechas',
        };
    }

    /** Heroicon name (available in Flux). */
    public function icon(): string
    {
        return match ($this) {
            self::Meeting => 'check-circle',
            self::AtRisk => 'exclamation-circle',
            self::Failing => 'exclamation-triangle',
            self::Neutral => 'clock',
        };
    }
}
