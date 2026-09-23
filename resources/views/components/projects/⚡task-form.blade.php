<?php

use App\Actions\Tasks\CreateTask;
use App\Actions\Tasks\ReportTaskProgress;
use App\Actions\Tasks\UpdateTask;
use App\Enums\TaskStatusKind;
use App\Livewire\Forms\TaskForm;
use App\Models\Project;
use App\Models\ProjectPriority;
use App\Models\ProjectTask;
use App\Models\ProjectTaskStatus;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Create / edit a task in a side panel. Opened through the
 * "open-task-form" event; announces changes with "task-saved".
 */
new class extends Component {
    #[Locked]
    public Project $project;

    public TaskForm $form;

    /** "full" for whoever manages the project, "progress" for the assignee. */
    #[Locked]
    public string $mode = 'full';

    #[On('open-task-form')]
    public function open(?int $taskId = null): void
    {
        if ($taskId === null) {
            $this->authorize('create', [ProjectTask::class, $this->project]);
            $this->mode = 'full';
            $this->form->forNew($this->project);
        } else {
            $task = $this->findTask($taskId);
            $this->mode = Auth::user()->can('update', $task) ? 'full' : 'progress';
            $this->authorize($this->mode === 'full' ? 'update' : 'updateProgress', $task);
            $this->form->forTask($task);
        }

        unset($this->dependencyOptions);
        Flux::modal('task-form')->show();
    }

    public function save(CreateTask $create, UpdateTask $update, ReportTaskProgress $report): void
    {
        $user = Auth::user();
        $task = $this->form->task?->setRelation('project', $this->project);

        if ($task === null) {
            $this->authorize('create', [ProjectTask::class, $this->project]);
            $create->handle($user, $this->project, $this->form->payload(), $this->form->dependencyIds());
            $message = 'Tarea creada.';
        } elseif ($this->mode === 'full') {
            $this->authorize('update', $task);
            $update->handle($user, $task, $this->form->payload(), $this->form->dependencyIds());
            $message = 'Tarea actualizada.';
        } else {
            $this->authorize('updateProgress', $task);
            $report->handle($user, $task, $this->form->progressPayload());
            $message = 'Avance registrado.';
        }

        Flux::modal('task-form')->close();
        Flux::toast(variant: 'success', text: $message);
        $this->dispatch('task-saved');
    }

    private function findTask(int $taskId): ProjectTask
    {
        return $this->project->tasks()->findOrFail($taskId)->setRelation('project', $this->project);
    }

    #[Computed]
    public function isCompleted(): bool
    {
        return $this->statuses->firstWhere('id', $this->form->status_id)?->kind === TaskStatusKind::Completed;
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
     * @return Collection<int, ProjectPriority>
     */
    #[Computed(persist: true)]
    public function priorities(): Collection
    {
        return ProjectPriority::query()->ordered()->get();
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function assignees(): Collection
    {
        return User::query()
            ->whereKey($this->project->memberships()->pluck('user_id')->push($this->project->owner_id))
            ->where(fn ($query) => $query->whereNull('deactivated_at')->orWhere('id', $this->form->assignee_id === '' ? null : (int) $this->form->assignee_id))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Other tasks of the project that may be chosen as predecessors.
     *
     * @return Collection<int, ProjectTask>
     */
    #[Computed]
    public function dependencyOptions(): Collection
    {
        return $this->project->tasks()
            ->when($this->form->task, fn ($query) => $query->whereKeyNot($this->form->task->id))
            ->orderBy('sort_order')
            ->get(['id', 'name']);
    }
}; ?>

<div>
    <flux:modal name="task-form" flyout class="w-full max-w-xl">
        <form wire:submit="save" class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">
                    @if ($mode === 'progress')
                        Actualizar avance
                    @else
                        {{ $form->task ? 'Editar tarea' : 'Nueva tarea' }}
                    @endif
                </flux:heading>
                <flux:text class="mt-1">{{ $project->code }} · {{ $project->name }}</flux:text>
            </div>

            @if ($mode === 'progress')
                <flux:callout icon="information-circle" variant="secondary">
                    <flux:callout.heading>{{ $form->name }}</flux:callout.heading>
                    <flux:callout.text>Como responsable de la tarea puedes actualizar su estado, avance y observaciones.</flux:callout.text>
                </flux:callout>
            @else
                <flux:input wire:model="form.name" label="Nombre" required />
                <flux:textarea wire:model="form.description" label="Descripción" rows="3" />

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model="form.assignee_id" label="Responsable">
                        <flux:select.option value="">Sin asignar</flux:select.option>
                        {{-- Only the project owner and its team can own a task. --}}
                        @foreach ($this->assignees as $option)
                            <flux:select.option :value="$option->id" wire:key="assignee-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model="form.priority_id" label="Prioridad">
                        @foreach ($this->priorities as $option)
                            <flux:select.option :value="$option->id" wire:key="priority-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:input type="date" wire:model="form.start_date" label="Inicio" />
                    <flux:input type="date" wire:model="form.due_date" label="Vencimiento" />
                </div>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model.live="form.status_id" label="Estado">
                    @foreach ($this->statuses as $option)
                        <flux:select.option :value="$option->id" wire:key="status-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($this->isCompleted)
                    <flux:input type="date" wire:model="form.completed_at" label="Finalización real (vacío = hoy)" />
                @else
                    <flux:input type="number" wire:model="form.progress" label="Avance (%)" min="0" max="100" step="1" />
                @endif

                @if ($mode === 'full')
                    <flux:input type="number" wire:model="form.weight" label="Peso en el avance del proyecto" min="1" max="100" />
                @endif
            </div>

            @if ($mode === 'full' && $this->dependencyOptions->isNotEmpty())
                <flux:checkbox.group wire:model="form.dependencies" label="Depende de" description="Tareas que deben avanzar antes que esta">
                    <div class="flex max-h-48 flex-col gap-2 overflow-y-auto rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        @foreach ($this->dependencyOptions as $option)
                            <flux:checkbox :value="(string) $option->id" :label="$option->name" wire:key="dep-{{ $option->id }}" />
                        @endforeach
                    </div>
                </flux:checkbox.group>
            @endif

            <flux:textarea wire:model="form.notes" label="Observaciones" rows="3" />

            <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Guardar</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
