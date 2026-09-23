@props(['priority'])

<flux:badge size="sm" variant="pill" :color="$priority->color" icon="flag" {{ $attributes }}>
    {{ $priority->name }}
</flux:badge>
