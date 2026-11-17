<?php

namespace App\Enums;

/**
 * Traffic-light reading of a management indicator (RAG status, as in
 * project status reports): green meets the goal, amber is a warning,
 * red is critical. Always shown with an icon and a label, never by
 * color alone.
 */
enum KpiLevel: string
{
    case Good = 'good';
    case Warning = 'warning';
    case Critical = 'critical';
    case NoData = 'no_data';

    /**
     * Goal (green from here) and warning floor (amber from here, red below)
     * per dashboard indicator, in percent.
     */
    public const THRESHOLDS = [
        'schedule' => [95, 85],
        'on_time' => [80, 60],
        'delivered' => [90, 75],
        'tasks' => [90, 75],
    ];

    public static function for(string $indicator, ?int $value): self
    {
        [$goal, $floor] = self::THRESHOLDS[$indicator];

        return match (true) {
            $value === null => self::NoData,
            $value >= $goal => self::Good,
            $value >= $floor => self::Warning,
            default => self::Critical,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Good => 'En meta',
            self::Warning => 'En alerta',
            self::Critical => 'Crítico',
            self::NoData => 'Sin datos',
        };
    }

    /** Heroicon name (available in Flux). */
    public function icon(): string
    {
        return match ($this) {
            self::Good => 'check-circle',
            self::Warning => 'exclamation-circle',
            self::Critical => 'x-circle',
            self::NoData => 'minus-circle',
        };
    }
}
