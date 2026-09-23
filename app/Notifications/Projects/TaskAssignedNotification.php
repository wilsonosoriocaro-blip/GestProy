<?php

namespace App\Notifications\Projects;

use App\Models\ProjectTask;

class TaskAssignedNotification extends ProjectNotification
{
    public function __construct(public readonly ProjectTask $task, public readonly ?string $assignedBy = null)
    {
        parent::__construct();
    }

    public function title(): string
    {
        return 'Te asignaron una tarea';
    }

    public function body(): string
    {
        $due = $this->task->due_date ? ' Vence el '.$this->task->due_date->translatedFormat('d M Y').'.' : '';
        $by = $this->assignedBy ? " {$this->assignedBy} te asignó" : ' Te asignaron';

        return trim("{$by} «{$this->task->name}» en {$this->task->project->code} · {$this->task->project->name}.{$due}");
    }

    public function url(): string
    {
        return route('projects.show', $this->task->project_id);
    }

    public function icon(): string
    {
        return 'clipboard-document-check';
    }
}
