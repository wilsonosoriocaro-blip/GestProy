@props(['value', 'expected' => null, 'label' => 'Avance'])

@php
    $value = max(0, min(100, (int) $value));
@endphp

<div {{ $attributes->class('min-w-24') }}>
    <div class="mb-1 flex items-baseline justify-between gap-2 text-xs">
        <span class="font-medium tabular-nums text-zinc-800 dark:text-white">{{ $value }}%</span>
        @if ($expected !== null)
            <span class="tabular-nums text-zinc-500 dark:text-zinc-400">esperado {{ $expected }}%</span>
        @endif
    </div>

    <div class="relative">
        <flux:progress :value="$value" aria-label="{{ $label }}: {{ $value }}%{{ $expected !== null ? ', esperado '.$expected.'%' : '' }}" />

        @if ($expected !== null)
            {{-- Marker of the progress a linear plan would have today. --}}
            <span class="absolute -top-0.5 h-2.5 w-0.5 rounded bg-zinc-800 dark:bg-white" style="left: calc({{ max(0, min(100, $expected)) }}% - 1px)" aria-hidden="true"></span>
        @endif
    </div>
</div>
