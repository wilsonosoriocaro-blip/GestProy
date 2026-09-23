@props(['zoom'])

<flux:button.group {{ $attributes }} aria-label="Escala del cronograma">
    @foreach (App\Services\Projects\Gantt\GanttZoom::cases() as $option)
        <flux:button size="sm" wire:click="$set('zoom', '{{ $option->value }}')" :variant="$zoom === $option->value ? 'primary' : 'outline'"
            aria-pressed="{{ $zoom === $option->value ? 'true' : 'false' }}" wire:key="zoom-{{ $option->value }}">
            {{ $option->label() }}
        </flux:button>
    @endforeach
</flux:button.group>
