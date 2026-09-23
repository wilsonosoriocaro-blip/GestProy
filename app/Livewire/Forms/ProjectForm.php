<?php

namespace App\Livewire\Forms;

use App\Enums\ProgressMode;
use App\Models\Project;
use App\Models\ProjectPriority;
use App\Models\ProjectStatus;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Form;

class ProjectForm extends Form
{
    #[Locked]
    public ?Project $project = null;

    public string $code = '';

    public string $name = '';

    public string $description = '';

    public string $objective = '';

    public string $scope = '';

    public ?int $category_id = null;

    public ?int $status_id = null;

    public ?int $priority_id = null;

    public ?int $owner_id = null;

    public string $start_date = '';

    public string $due_date = '';

    public string $completed_at = '';

    public string $progress_mode = 'tasks';

    public int $progress = 0;

    public string $budget = '';

    public string $notes = '';

    /**
     * Defaults for a new project: default status and priority, current user as owner.
     */
    public function setDefaults(int $ownerId): void
    {
        $this->status_id = ProjectStatus::query()->where('is_default', true)->value('id');
        $this->priority_id = ProjectPriority::query()->where('is_default', true)->value('id');
        $this->owner_id = $ownerId;
        $this->progress_mode = ProgressMode::Tasks->value;
    }

    public function setProject(Project $project): void
    {
        $this->project = $project;
        $this->code = $project->code;
        $this->name = $project->name;
        $this->description = $project->description ?? '';
        $this->objective = $project->objective ?? '';
        $this->scope = $project->scope ?? '';
        $this->category_id = $project->category_id;
        $this->status_id = $project->status_id;
        $this->priority_id = $project->priority_id;
        $this->owner_id = $project->owner_id;
        $this->start_date = $project->start_date?->toDateString() ?? '';
        $this->due_date = $project->due_date?->toDateString() ?? '';
        $this->completed_at = $project->completed_at?->toDateString() ?? '';
        $this->progress_mode = $project->progress_mode->value;
        $this->progress = $project->progress;
        $this->budget = $project->budget ?? '';
        $this->notes = $project->notes ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $current = $this->project?->id;

        return [
            'code' => [Rule::requiredIf($current !== null), 'nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9][A-Za-z0-9-]*$/', Rule::unique('projects', 'code')->ignore($current)],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'objective' => ['nullable', 'string', 'max:5000'],
            'scope' => ['nullable', 'string', 'max:5000'],
            // Inactive catalog entries stay valid for projects that already use them.
            'category_id' => ['required', 'integer', Rule::exists('project_categories', 'id')->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $this->project?->category_id))],
            'status_id' => ['required', 'integer', Rule::exists('project_statuses', 'id')->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $this->project?->status_id))],
            'priority_id' => ['required', 'integer', Rule::exists('project_priorities', 'id')->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $this->project?->priority_id))],
            'owner_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'completed_at' => ['nullable', 'date'],
            'progress_mode' => ['required', Rule::enum(ProgressMode::class)],
            'progress' => ['required', 'integer', 'between:0,100'],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'code' => 'código',
            'name' => 'nombre',
            'description' => 'descripción',
            'objective' => 'objetivo',
            'scope' => 'alcance',
            'category_id' => 'categoría',
            'status_id' => 'estado',
            'priority_id' => 'prioridad',
            'owner_id' => 'responsable',
            'start_date' => 'fecha de inicio',
            'due_date' => 'fecha estimada de finalización',
            'completed_at' => 'fecha real de finalización',
            'progress_mode' => 'modo de avance',
            'progress' => 'avance',
            'budget' => 'presupuesto',
            'notes' => 'observaciones',
        ];
    }

    /**
     * Validated attributes ready for CreateProject / UpdateProject.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validate();

        $data['code'] = filled($data['code']) ? strtoupper($data['code']) : null;

        foreach (['description', 'objective', 'scope', 'notes', 'start_date', 'due_date', 'completed_at', 'budget'] as $optional) {
            $data[$optional] = filled($data[$optional]) ? $data[$optional] : null;
        }

        return $data;
    }
}
