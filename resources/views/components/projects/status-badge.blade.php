@props(['status'])

<flux:badge size="sm" :color="$status->color" :icon="$status->icon" {{ $attributes }}>
    {{ $status->name }}
</flux:badge>
