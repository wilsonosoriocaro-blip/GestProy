{{--
    Horizontal bars for one series: every bar in the same accent color, the
    category named by its badge and the value always printed next to the bar.
    Items: list of ['name', 'count', 'color', 'icon', 'href' => optional].
--}}
@props(['items', 'label', 'unit' => 'proyectos'])

@php
    $max = max(1, ...array_map(fn ($item) => $item['count'], $items ?: [['count' => 0]]));
@endphp

<ul {{ $attributes->class('flex flex-col gap-2.5') }} aria-label="{{ $label }}">
    @forelse ($items as $item)
        <li class="grid grid-cols-[minmax(0,9rem)_1fr_2.5rem] items-center gap-3 text-sm"
            title="{{ $item['name'] }}: {{ $item['count'] }} {{ $unit }}">
            <span class="min-w-0">
                <flux:badge size="sm" :color="$item['color']" :icon="$item['icon']" class="max-w-full truncate">{{ $item['name'] }}</flux:badge>
            </span>
            <span class="relative h-3 rounded-e bg-zinc-100 dark:bg-zinc-700/60" aria-hidden="true">
                <span class="absolute inset-y-0 start-0 rounded-e bg-blue-600 dark:bg-blue-500" style="width: {{ $item['count'] / $max * 100 }}%"></span>
            </span>
            <span class="text-end font-medium tabular-nums text-zinc-800 dark:text-white">
                @if (! empty($item['href']))
                    <a href="{{ $item['href'] }}" wire:navigate class="underline-offset-2 hover:underline">{{ $item['count'] }}</a>
                @else
                    {{ $item['count'] }}
                @endif
            </span>
        </li>
    @empty
        <li class="text-sm text-zinc-500 dark:text-zinc-400">Sin datos.</li>
    @endforelse
</ul>
