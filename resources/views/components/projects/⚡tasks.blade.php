<?php

use App\Actions\Tasks\DeleteTask;
use App\Enums\TaskStatusKind;
use App\Models\Project;
use App\Models\ProjectActivityLog;
use App\Models\ProjectTask;
use App\Models\ProjectTaskStatus;
use App\Models\User;
use App\Queries\Projects\TaskIndexQuery;
use App\Queries\Projects\TaskSignals;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    #[Locked]
    public Project $project;

    public string $search = '';

    public string $status = '';

    public string $assignee = '';

    public string $kind = '';

    public string $signal = '';

    public bool $hideClosed = false;

    public string $sort = '';

    public string $direction = 'asc';

    #[Locked]
    public ?int $deletingId = null;

    #[Locked]
    public ?int $historyId = null;

    public function mount(Project $project): void
    {
        $this->authorize('viewAny', [ProjectTask::class, $project]);
    }

    public function updated(string $property): void
    {
        if (! in_array($property, ['sort', 'direction'], true)) {
            $this->resetPage('tasks');
        }
    }

    public function sortBy(string $column): void
    {
        if (! array_key_exists($column, TaskIndexQuery::SORTABLE)) {
            return;
        }

        $this->direction = $this->sort === $column && $this->direction === 'asc' ? 'desc' : 'asc';
        $this->sort = $column;
    }

    /**
     * Counter chips: filter by one status kind or one alert at a time.
     */
    public function focus(string $type, string $value): void
    {
        $current = $type === 'kind' ? $this->kind : $this->signal;
        $this->reset('kind', 'signal', 'status');

        if ($current !== $value) {
            $type === 'kind' ? $this->kind = $value : $this->signal = $value;
        }

        $this->resetPage('tasks');
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'assignee', 'kind', 'signal', 'hideClosed');
        $this->resetPage('tasks');
    }

    #[On('task-saved')]
    public function refreshTasks(): void
    {
        unset($this->tasks, $this->summary);
    }

    public function create(): void
    {
        $this->dispatch('open-task-form')->to('projects.task-form');
    }

    public function edit(int $taskId): void
    {
        $this->dispatch('open-task-form', taskId: $taskId)->to('projects.task-form');
    }

    public function confirmDelete(int $taskId): void
    {
        $this->authorize('delete', $this->findTask($taskId));
        $this->deletingId = $taskId;
        Flux::modal('confirm-delete-task')->show();
    }

    public function delete(DeleteTask $action): void
    {
        $task = $this->findTask((int) $this->deletingId);
        $this->authorize('delete', $task);

        $action->handle(Auth::user(), $task);

        $this->deletingId = null;
        Flux::modal('confirm-delete-task')->close();
        Flux::toast(variant: 'success', text: 'Tarea eliminada.');
        $this->dispatch('task-saved');
    }

    public function showHistory(int $taskId): void
    {
        $this->authorize('view', $this->findTask($taskId));
        $this->historyId = $taskId;
        Flux::modal('task-history')->show();
    }

    private function findTask(int $taskId): ProjectTask
    {
        $task = $this->project->tasks()->findOrFail($taskId);

        return $task->setRelation('project', $this->project);
    }

    /**
     * @return LengthAwarePaginator<int, ProjectTask>
     */
    #[Computed]
    public function tasks(): LengthAwarePaginator
    {
        $tasks = app(TaskIndexQuery::class)
            ->build($this->project, [
                'search' => $this->search,
                'status' => $this->status,
                'assignee' => $this->assignee,
                'kind' => $this->kind,
                'signal' => $this->signal,
                'hide_closed' => $this->hideClosed,
                'sort' => $this->sort,
                'direction' => $this->direction,
            ])
            ->paginate(20, pageName: 'tasks');

        // Policies read the project; reuse the loaded one instead of one query per row.
        $tasks->getCollection()->each->setRelation('project', $this->project);

        return $tasks;
    }

    /**
     * @return array{total: int, by_kind: array<string, int>, signals: array<string, int>}
     */
    #[Computed]
    public function summary(): array
    {
        return TaskSignals::make()->summarize(TaskSignals::tasksOf($this->project->id));
    }

    /**
     * @return Collection<int, ProjectTaskStatus>
     */
    #[Computed(persist: true)]
    public function statuses(): Collection
    {
        return ProjectTaskStatus::query()->ordered()->get();
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function assignees(): Collection
    {
        return User::query()
            ->whereKey($this->project->memberships()->pluck('user_id')->push($this->project->owner_id))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * @return Collection<int, ProjectActivityLog>
     */
    #[Computed]
    public function history(): Collection
    {
        if ($this->historyId === null) {
            return new Collection;
        }

        return ProjectActivityLog::query()
            ->where('project_id', $this->project->id)
            ->where('task_id', $this->historyId)
            ->with('user:id,name')
            ->latest('created_at')->latest('id')
            ->get();
    }

    #[Computed]
    public function historyTask(): ?ProjectTask
    {
        return $this->historyId ? $this->project->tasks()->withTrashed()->find($this->historyId) : null;
    }

    #[Computed]
    public function deletingTask(): ?ProjectTask
    {
        return $this->deletingId ? $this->project->tasks()->find($this->deletingId) : null;
    }

    #[Computed]
    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->status !== '' || $this->assignee !== ''
            || $this->kind !== '' || $this->signal !== '' || $this->hideClosed;
    }
}; ?>

@php
    $chips = [
        ['kind', TaskStatusKind::Pending->value, 'Pendientes', $this->summary['by_kind']['pending'], 'clock', 'text-zinc-500 dark:text-zinc-400'],
        ['kind', TaskStatusKind::InProgress->value, 'En ejecución', $this->summary['by_kind']['in_progress'], 'play-circle', 'text-blue-600 dark:text-blue-400'],
        ['signal', TaskSignals::BLOCKED, 'Bloqueadas', $this->summary['signals']['blocked'], 'no-symbol', 'text-red-600 dark:text-red-400'],
        ['signal', TaskSignals::OVERDUE, 'Vencidas', $this->summary['signals']['overdue'], 'exclamation-triangle', 'text-red-600 dark:text-red-400'],
        ['signal', TaskSignals::DUE_SOON, 'Próximas a vencer', $this->summary['signals']['due_soon'], 'bell-alert', 'text-amber-600 dark:text-amber-400'],
        ['signal', TaskSignals::WITHOUT_PROGRESS, 'Sin avance', $this->summary['signals']['without_progress'], 'pause', 'text-orange-600 dark:text-orange-400'],
        ['kind', TaskStatusKind::Completed->value, 'Finalizadas', $this->summary['by_kind']['completed'], 'check-circle', 'text-green-600 dark:text-green-400'],
    ];
@endphp

<section class="flex flex-col gap-4" aria-labelledby="tasks-heading">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading id="tasks-heading" level="2" size="lg">Tareas</flux:heading>
            <flux:text class="text-sm">{{ $this->summary['total'] }} {{ $this->summary['total'] === 1 ? 'tarea' : 'tareas' }} en el proyecto</flux:text>
        </div>

        @can('create', [App\Models\ProjectTask::class, $project])
            <flux:button variant="primary" icon="plus" wire:click="create">Nueva tarea</flux:button>
        @endcan
    </div>

    {{-- Counters double as quick filters --}}
    <div class="flex flex-wrap gap-2" role="group" aria-label="Filtros rápidos de tareas">
        @foreach ($chips as [$type, $value, $label, $count, $icon, $color])
            @php($active = ($type === 'kind' ? $kind : $signal) === $value)
            <button type="button" wire:click="focus('{{ $type }}', '{{ $value }}')" wire:key="chip-{{ $value }}"
                aria-pressed="{{ $active ? 'true' : 'false' }}"
                @class([
                    'inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm transition',
                    'border-zinc-800 bg-zinc-800 text-white dark:border-white dark:bg-white dark:text-zinc-900' => $active,
                    'border-zinc-200 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800' => ! $active,
                    'opacity-60' => $count === 0 && ! $active,
                ])>
                <flux:icon :name="$icon" variant="micro" @class([$color => ! $active]) />
                {{ $label }}
                <span class="font-semibold tabular-nums">{{ $count }}</span>
            </button>
        @endforeach
    </div>

    <div class="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="sm:col-span-2">
            <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" placeholder="Buscar tarea" aria-label="Buscar tarea" clearable />
        </div>

        <flux:select wire:model.live="status" aria-label="Estado de la tarea">
            <flux:select.option value="">Todos los estados</flux:select.option>
            @foreach ($this->statuses as $option)
                <flux:select.option :value="$option->id" wire:key="task-status-{{ $option->id }}">{{ $option->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="assignee" aria-label="Responsable de la tarea">
            <flux:select.option value="">Todos los responsables</flux:select.option>
            <flux:select.option value="none">Sin asignar</flux:select.option>
            @foreach ($this->assignees as $option)
                <flux:select.option :value="$option->id" wire:key="task-assignee-{{ $option->id }}">{{ $option->name }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="flex flex-wrap items-center gap-4">
        <flux:checkbox wire:model.live="hideClosed" label="Ocultar finalizadas y canceladas" />

        @if ($this->hasFilters)
            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearFilters">Limpiar filtros</flux:button>
        @endif

        <flux:text class="ms-auto text-sm" wire:loading wire:target="search, status, assignee, focus, hideClosed, sortBy, clearFilters, gotoPage, nextPage, previousPage">Actualizando…</flux:text>
    </div>

    @if ($this->tasks->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-600">
            <flux:icon name="clipboard-document-list" class="mx-auto text-zinc-400" />
            <flux:heading class="mt-2">{{ $this->hasFilters ? 'Ninguna tarea coincide con los filtros' : 'Este proyecto todavía no tiene tareas' }}</flux:heading>
        </div>
    @else
        <div wire:loading.class="opacity-60" wire:target="search, status, assignee, focus, hideClosed, sortBy, clearFilters, gotoPage, nextPage, previousPage" class="transition-opacity">
            {{-- Small screens: cards --}}
            <ul class="flex flex-col gap-3 md:hidden">
                @foreach ($this->tasks as $task)
                    @php($schedule = $task->schedule())
                    <li wire:key="task-card-{{ $task->id }}" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="flex items-start justify-between gap-2">
                            <div class="font-medium">{{ $task->name }}</div>
                            @include('components.projects.partials.task-actions', ['task' => $task])
                        </div>
                        <div class="mt-2 flex flex-wrap gap-1">
                            <x-projects.status-badge :status="$task->status" />
                            <x-projects.priority-badge :priority="$task->priority" />
                        </div>
                        <x-projects.progress-bar class="mt-3" :value="$task->progress" :expected="$schedule->expectedProgress" />
                        <div class="mt-3 flex items-end justify-between gap-2">
                            <x-projects.health-badge :schedule="$schedule" />
                            <span class="text-end text-xs text-zinc-500 dark:text-zinc-400">{{ $task->assignee?->name ?? 'Sin asignar' }}<br>vence {{ $task->due_date?->translatedFormat('d M Y') ?? 'sin fecha' }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
            <div class="mt-4 md:hidden">
                <flux:pagination :paginator="$this->tasks" />
            </div>

            <flux:table :paginator="$this->tasks" class="max-md:hidden">
                <flux:table.columns>
                    <flux:table.column sortable :sorted="$sort === 'name'" :direction="$direction" wire:click="sortBy('name')">Tarea</flux:table.column>
                    <flux:table.column>Responsable</flux:table.column>
                    <flux:table.column>Estado</flux:table.column>
                    <flux:table.column sortable :sorted="$sort === 'priority'" :direction="$direction" wire:click="sortBy('priority')">Prioridad</flux:table.column>
                    <flux:table.column sortable :sorted="$sort === 'due_date'" :direction="$direction" wire:click="sortBy('due_date')">Fechas</flux:table.column>
                    <flux:table.column sortable :sorted="$sort === 'progress'" :direction="$direction" wire:click="sortBy('progress')">Avance</flux:table.column>
                    <flux:table.column>Cronograma</flux:table.column>
                    <flux:table.column><span class="sr-only">Acciones</span></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->tasks as $task)
                        @php($schedule = $task->schedule())
                        <flux:table.row :key="'task-'.$task->id">
                            <flux:table.cell class="min-w-56 max-w-80 whitespace-normal">
                                <div class="font-medium text-zinc-800 dark:text-white">{{ $task->name }}</div>
                                @if ($task->dependencies_count > 0)
                                    <div class="mt-0.5 flex items-center gap-1 text-xs text-zinc-500 dark:text-zinc-400">
                                        <flux:icon name="link" variant="micro" />
                                        <span>Depende de: {{ $task->dependencies->pluck('name')->join(', ') }}</span>
                                    </div>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap">
                                {{ $task->assignee?->name ?? 'Sin asignar' }}
                            </flux:table.cell>
                            <flux:table.cell><x-projects.status-badge :status="$task->status" /></flux:table.cell>
                            <flux:table.cell><x-projects.priority-badge :priority="$task->priority" /></flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap text-xs">
                                <div>{{ $task->start_date?->translatedFormat('d M Y') ?? 'Sin inicio' }}</div>
                                <div class="text-zinc-500 dark:text-zinc-400">→ {{ $task->due_date?->translatedFormat('d M Y') ?? 'Sin vencimiento' }}</div>
                            </flux:table.cell>
                            <flux:table.cell class="min-w-32">
                                <x-projects.progress-bar :value="$task->progress" :expected="$schedule->expectedProgress" />
                            </flux:table.cell>
                            <flux:table.cell><x-projects.health-badge :schedule="$schedule" /></flux:table.cell>
                            <flux:table.cell>
                                @include('components.projects.partials.task-actions', ['task' => $task])
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif

    {{-- Delete confirmation --}}
    <flux:modal name="confirm-delete-task" class="max-w-md">
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">¿Eliminar la tarea?</flux:heading>
            <flux:text>{{ $this->deletingTask?->name }} dejará de aparecer en el proyecto y el avance se recalculará.</flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="delete">Eliminar</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Task history --}}
    <flux:modal name="task-history" flyout class="w-full max-w-md">
        <flux:heading size="lg">Historial</flux:heading>
        <flux:text class="mt-1">{{ $this->historyTask?->name }}</flux:text>

        @if ($this->history->isEmpty())
            <flux:text class="mt-6">Sin cambios registrados.</flux:text>
        @else
            <ol class="mt-6 flex flex-col gap-4 border-s border-zinc-200 ps-4 dark:border-zinc-700">
                @foreach ($this->history as $entry)
                    <li wire:key="task-log-{{ $entry->id }}" class="text-sm">
                        <div class="font-medium text-zinc-800 dark:text-white">{{ $entry->event->label() }}</div>
                        @if ($entry->description)
                            <div class="text-zinc-600 dark:text-zinc-300">{{ $entry->description }}</div>
                        @endif
                        @if ($entry->event === App\Enums\ProjectActivityEvent::TaskUpdated && $entry->new_values)
                            <ul class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                @foreach ($entry->new_values as $field => $value)
                                    <li>{{ $field }}: {{ is_array($entry->old_values[$field] ?? null) ? json_encode($entry->old_values[$field]) : ($entry->old_values[$field] ?? '—') }} → {{ is_array($value) ? json_encode($value) : ($value ?? '—') }}</li>
                                @endforeach
                            </ul>
                        @endif
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">
                            {{ $entry->user?->name ?? 'Sistema' }} ·
                            <time datetime="{{ $entry->created_at->toIso8601String() }}">{{ $entry->created_at->translatedFormat('d M Y, h:i a') }}</time>
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
    </flux:modal>
</section>
