<?php

namespace App\Notifications\Projects;

use App\Models\Project;

class ProjectOwnerAssignedNotification extends ProjectNotification
{
    public function __construct(public readonly Project $project, public readonly ?string $assignedBy = null)
    {
        parent::__construct();
    }

    public function title(): string
    {
        return 'Ahora eres responsable de un proyecto';
    }

    public function body(): string
    {
        $by = $this->assignedBy ? "{$this->assignedBy} te asignó como responsable de" : 'Te asignaron como responsable de';

        return "{$by} {$this->project->code} · {$this->project->name}.";
    }

    public function url(): string
    {
        return route('projects.show', $this->project);
    }

    public function icon(): string
    {
        return 'briefcase';
    }
}
