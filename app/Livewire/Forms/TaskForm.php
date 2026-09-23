<?php

namespace App\Livewire\Forms;

use App\Models\Project;
use App\Models\ProjectPriority;
use App\Models\ProjectTask;
use App\Models\ProjectTaskStatus;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Form;

class TaskForm extends Form
{
    #[Locked]
    public ?int $projectId = null;

    #[Locked]
    public ?ProjectTask $task = null;

    public string $name = '';

    public string $description = '';

    public string $assignee_id = '';

    public ?int $status_id = null;

    public ?int $priority_id = null;

    public string $start_date = '';

    public string $due_date = '';

    public string $completed_at = '';

    public int $progress = 0;

    public int $weight = 1;

    public string $notes = '';

    /** @var list<string> */
    public array $dependencies = [];

    public function forNew(Project $project): void
    {
        $this->reset();
        $this->resetValidation();
        $this->projectId = $project->id;
        $this->status_id = ProjectTaskStatus::query()->where('is_default', true)->value('id');
        $this->priority_id = ProjectPriority::query()->where('is_default', true)->value('id');
    }

    public function forTask(ProjectTask $task): void
    {
        $this->reset();
        $this->resetValidation();
        $this->projectId = $task->project_id;
        $this->task = $task;
        $this->name = $task->name;
        $this->description = $task->description ?? '';
        $this->assignee_id = (string) ($task->assignee_id ?? '');
        $this->status_id = $task->status_id;
        $this->priority_id = $task->priority_id;
        $this->start_date = $task->start_date?->toDateString() ?? '';
        $this->due_date = $task->due_date?->toDateString() ?? '';
        $this->completed_at = $task->completed_at?->toDateString() ?? '';
        $this->progress = $task->progress;
        $this->weight = $task->weight;
        $this->notes = $task->notes ?? '';
        $this->dependencies = array_values(array_map(strval(...), $task->dependencies()->pluck('project_tasks.id')->all()));
    }

    /**
     * People who may own a task: the project owner and its team.
     *
     * @return list<int>
     */
    public function assignableIds(): array
    {
        $project = Project::query()->findOrFail($this->projectId);
        $team = [$project->owner_id, ...$project->memberships()->pluck('user_id')->map(fn ($id): int => (int) $id)];

        // Active people only; the current assignee stays valid even if deactivated later.
        $active = User::query()->active()->whereKey($team)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return array_values(array_unique([...$active, ...array_filter([$this->task?->assignee_id])]));
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $activeOrCurrent = fn (string $table, ?int $current) => Rule::exists($table, 'id')
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $current));

        return [
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'assignee_id' => ['nullable', 'integer', Rule::in($this->assignableIds())],
            'status_id' => ['required', 'integer', $activeOrCurrent('project_task_statuses', $this->task?->status_id)],
            'priority_id' => ['required', 'integer', $activeOrCurrent('project_priorities', $this->task?->priority_id)],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'completed_at' => ['nullable', 'date'],
            'progress' => ['required', 'integer', 'between:0,100'],
            'weight' => ['required', 'integer', 'between:1,100'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'dependencies' => ['array'],
            'dependencies.*' => ['integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'name' => 'nombre',
            'description' => 'descripción',
            'assignee_id' => 'responsable',
            'status_id' => 'estado',
            'priority_id' => 'prioridad',
            'start_date' => 'fecha de inicio',
            'due_date' => 'fecha de vencimiento',
            'completed_at' => 'fecha real de finalización',
            'progress' => 'avance',
            'weight' => 'peso',
            'notes' => 'observaciones',
            'dependencies' => 'dependencias',
        ];
    }

    /**
     * Validated attributes for CreateTask / UpdateTask.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validate();
        unset($data['dependencies']);

        foreach (['description', 'notes', 'start_date', 'due_date', 'completed_at', 'assignee_id'] as $optional) {
            $data[$optional] = filled($data[$optional]) ? $data[$optional] : null;
        }

        return $data;
    }

    /**
     * Validated subset an assignee may change.
     *
     * @return array<string, mixed>
     */
    public function progressPayload(): array
    {
        $data = $this->validate(Arr::only($this->rules(), ['status_id', 'progress', 'notes', 'completed_at']));

        foreach (['notes', 'completed_at'] as $optional) {
            $data[$optional] = filled($data[$optional] ?? null) ? $data[$optional] : null;
        }

        return $data;
    }

    /**
     * @return list<int>
     */
    public function dependencyIds(): array
    {
        return array_values(array_unique(array_map('intval', $this->dependencies)));
    }
}
