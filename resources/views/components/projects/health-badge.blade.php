@props(['schedule'])

@php
    /** @var \App\Data\ScheduleSnapshot $schedule */
    $detail = match (true) {
        $schedule->health === \App\Enums\ScheduleHealth::Overdue => $schedule->overdueDays.' '.($schedule->overdueDays === 1 ? 'día hábil' : 'días hábiles').' de retraso',
        $schedule->health === \App\Enums\ScheduleHealth::CompletedLate => 'Terminó '.$schedule->overdueDays.' '.($schedule->overdueDays === 1 ? 'día hábil' : 'días hábiles').' tarde',
        $schedule->remainingDays !== null && ! $schedule->health->needsAttention() && $schedule->remainingDays > 0 => 'Faltan '.$schedule->remainingDays.' días hábiles',
        $schedule->remainingDays !== null && $schedule->remainingDays > 0 => $schedule->remainingDays.' '.($schedule->remainingDays === 1 ? 'día hábil restante' : 'días hábiles restantes'),
        default => null,
    };
@endphp

<div {{ $attributes->class('flex flex-col items-start gap-1') }}>
    <flux:badge size="sm" :color="$schedule->health->color()" :icon="$schedule->health->icon()">
        {{ $schedule->health->label() }}
    </flux:badge>

    @if ($detail)
        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $detail }}</span>
    @endif
</div>
