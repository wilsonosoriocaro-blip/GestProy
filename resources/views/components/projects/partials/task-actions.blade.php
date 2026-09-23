{{-- Row actions of a task. Expects $task with its project relation set. --}}
<flux:dropdown align="end">
    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" aria-label="Acciones de {{ $task->name }}" />
    <flux:menu>
        @can('update', $task)
            <flux:menu.item icon="pencil-square" wire:click="edit({{ $task->id }})">Editar</flux:menu.item>
        @elsecan('updateProgress', $task)
            <flux:menu.item icon="chart-bar" wire:click="edit({{ $task->id }})">Actualizar avance</flux:menu.item>
        @endcan
        <flux:menu.item icon="clock" wire:click="showHistory({{ $task->id }})">Historial</flux:menu.item>
        @can('delete', $task)
            <flux:menu.separator />
            <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $task->id }})">Eliminar</flux:menu.item>
        @endcan
    </flux:menu>
</flux:dropdown>
