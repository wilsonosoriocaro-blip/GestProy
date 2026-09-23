@props(['label', 'value' => null, 'hint' => null, 'icon' => null])

<div {{ $attributes->class('rounded-xl border border-zinc-200 p-4 dark:border-zinc-700') }}>
    <div class="flex items-center gap-2 text-sm text-zinc-500 dark:text-zinc-400">
        @if ($icon)
            <flux:icon :name="$icon" variant="micro" />
        @endif
        {{ $label }}
    </div>

    @if (! is_null($value))
        <div class="mt-1 text-2xl font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $value }}</div>
    @endif

    @if ($hint)
        <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $hint }}</div>
    @endif

    {{ $slot }}
</div>
