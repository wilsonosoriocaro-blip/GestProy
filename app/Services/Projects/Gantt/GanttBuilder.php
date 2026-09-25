<?php

namespace App\Services\Projects\Gantt;

use App\Support\Calendar\BusinessCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Turns dated items into pixel geometry for the timeline view: bars,
 * progress, overdue stretch, time axis, today marker, non-working days and
 * dependency links. Pure and deterministic, so the view only draws.
 *
 * Dates are inclusive: a task from Monday to Monday is one day wide.
 */
class GanttBuilder
{
    public function __construct(private readonly BusinessCalendar $calendar) {}

    /**
     * @param  list<GanttItem>  $items
     * @return array{
     *     empty: bool,
     *     start: string|null,
     *     end: string|null,
     *     width: int,
     *     px_per_day: int,
     *     months: list<array{label: string, x: int, w: int}>,
     *     weeks: list<array{label: string, x: int, w: int}>,
     *     non_working: list<array{x: int, w: int}>,
     *     today: int|null,
     *     rows: list<array<string, mixed>>,
     *     links: list<array{from: int, to: int, x1: int, x2: int}>,
     * }
     */
    public function build(array $items, GanttZoom $zoom, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();
        $px = $zoom->pixelsPerDay();
        [$start, $end] = $this->range($items, $zoom, $today);

        if ($start === null || $end === null) {
            return [
                'empty' => true, 'start' => null, 'end' => null, 'width' => 0, 'px_per_day' => $px,
                'months' => [], 'weeks' => [], 'non_working' => [], 'today' => null,
                'rows' => array_map(fn (GanttItem $item) => $this->row($item, null, $px, $today), $items),
                'links' => [],
            ];
        }

        $x = fn (CarbonImmutable $date): int => (int) $start->diffInDays($date) * $px;
        $rows = array_map(fn (GanttItem $item) => $this->row($item, $start, $px, $today), $items);

        return [
            'empty' => false,
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'width' => $x($end) + $px,
            'px_per_day' => $px,
            'months' => $this->months($start, $end, $x, $px),
            'weeks' => $zoom === GanttZoom::Week ? $this->weeks($start, $end, $x, $px) : [],
            'non_working' => $zoom === GanttZoom::Week ? $this->nonWorking($start, $end, $x, $px) : [],
            'today' => $today->betweenIncluded($start, $end) ? $x($today) : null,
            'rows' => $rows,
            'links' => $this->links($items, $rows),
        ];
    }

    /**
     * Visible window: every date of every item plus today, padded and
     * snapped to the start of a week (week zoom) or month.
     *
     * @param  list<GanttItem>  $items
     * @return array{CarbonImmutable|null, CarbonImmutable|null}
     */
    private function range(array $items, GanttZoom $zoom, CarbonImmutable $today): array
    {
        $dates = [];

        foreach ($items as $item) {
            array_push($dates, ...array_filter([$item->start, $item->end]));
        }

        if ($dates === []) {
            return [null, null];
        }

        $dates[] = $today;
        $min = min($dates)->subDays($zoom->padding());
        $max = max($dates)->addDays($zoom->padding());

        return $zoom === GanttZoom::Week
            ? [$min->startOfWeek(CarbonImmutable::MONDAY), $max->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay()]
            : [$min->startOfMonth(), $max->endOfMonth()->startOfDay()];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(GanttItem $item, ?CarbonImmutable $origin, int $px, CarbonImmutable $today): array
    {
        $row = [
            'key' => $item->key,
            'label' => $item->label,
            'sublabel' => $item->sublabel,
            'href' => $item->href,
            'progress' => $item->progress,
            'health' => $item->health->value,
            'dates' => $this->dateLabel($item),
            'color' => $item->color,
            'compliance' => $item->compliance,
            'has_bar' => false,
            'milestone' => false,
            'x' => 0,
            'w' => 0,
            'overdue_x' => null,
            'overdue_w' => null,
        ];

        if ($origin === null || ($item->start === null && $item->end === null)) {
            return $row;
        }

        // Only one date: draw a milestone on it.
        if ($item->start === null || $item->end === null) {
            $date = $item->end ?? $item->start;

            return ['has_bar' => true, 'milestone' => true, 'x' => (int) $origin->diffInDays($date) * $px + intdiv($px, 2), 'w' => 0] + $row;
        }

        $x = (int) $origin->diffInDays($item->start) * $px;
        $w = ((int) $item->start->diffInDays($item->end) + 1) * $px;

        $row = ['has_bar' => true, 'x' => $x, 'w' => $w] + $row;

        // Open item past its due date: the stretch from the day after the due date to today.
        if ($item->isOpen && $today->gt($item->end)) {
            $row['overdue_x'] = $x + $w;
            $row['overdue_w'] = (int) $item->end->diffInDays($today) * $px;
        }

        return $row;
    }

    private function dateLabel(GanttItem $item): string
    {
        $format = fn (CarbonImmutable $date): string => $date->translatedFormat('d M Y');

        return match (true) {
            $item->start !== null && $item->end !== null => $format($item->start).' – '.$format($item->end),
            $item->end !== null => 'Vence '.$format($item->end),
            $item->start !== null => 'Inicia '.$format($item->start),
            default => 'Sin fechas',
        };
    }

    /**
     * @param  \Closure(CarbonImmutable): int  $x
     * @return list<array{label: string, x: int, w: int}>
     */
    private function months(CarbonImmutable $start, CarbonImmutable $end, \Closure $x, int $px): array
    {
        $months = [];

        for ($cursor = $start->startOfMonth(); $cursor->lte($end); $cursor = $cursor->addMonth()) {
            $from = $cursor->max($start);
            $to = $cursor->endOfMonth()->startOfDay()->min($end);

            $months[] = [
                'label' => Str::ucfirst($cursor->translatedFormat($px >= 9 ? 'F Y' : 'M y')),
                'x' => $x($from),
                'w' => ((int) $from->diffInDays($to) + 1) * $px,
            ];
        }

        return $months;
    }

    /**
     * @param  \Closure(CarbonImmutable): int  $x
     * @return list<array{label: string, x: int, w: int}>
     */
    private function weeks(CarbonImmutable $start, CarbonImmutable $end, \Closure $x, int $px): array
    {
        $weeks = [];

        for ($cursor = $start; $cursor->lte($end); $cursor = $cursor->addWeek()) {
            $weeks[] = ['label' => $cursor->translatedFormat('d M'), 'x' => $x($cursor), 'w' => 7 * $px];
        }

        return $weeks;
    }

    /**
     * Weekends, holidays and configured days off, merged into runs.
     *
     * @param  \Closure(CarbonImmutable): int  $x
     * @return list<array{x: int, w: int}>
     */
    private function nonWorking(CarbonImmutable $start, CarbonImmutable $end, \Closure $x, int $px): array
    {
        $runs = [];

        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            if ($this->calendar->isBusinessDay($day)) {
                continue;
            }

            $last = array_key_last($runs);

            if ($last !== null && $runs[$last]['x'] + $runs[$last]['w'] === $x($day)) {
                $runs[$last]['w'] += $px;
            } else {
                $runs[] = ['x' => $x($day), 'w' => $px];
            }
        }

        return $runs;
    }

    /**
     * Finish-to-start arrows between rows that are both drawn as bars.
     *
     * @param  list<GanttItem>  $items
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{from: int, to: int, x1: int, x2: int}>
     */
    private function links(array $items, array $rows): array
    {
        $index = [];

        foreach ($rows as $i => $row) {
            $index[$row['key']] = $i;
        }

        $links = [];

        foreach ($items as $to => $item) {
            foreach ($item->dependsOn as $key) {
                $from = $index[$key] ?? null;

                if ($from === null || ! $rows[$from]['has_bar'] || ! $rows[$to]['has_bar']) {
                    continue;
                }

                $links[] = [
                    'from' => $from,
                    'to' => $to,
                    'x1' => (int) $rows[$from]['x'] + (int) $rows[$from]['w'],
                    'x2' => (int) $rows[$to]['x'],
                ];
            }
        }

        return $links;
    }
}
