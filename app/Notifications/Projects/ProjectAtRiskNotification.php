<?php

namespace App\Notifications\Projects;

use App\Models\Project;

class ProjectAtRiskNotification extends ProjectNotification
{
    public function __construct(public readonly Project $project, public readonly ?string $changedBy = null)
    {
        parent::__construct();
    }

    public function title(): string
    {
        return "Proyecto en riesgo: {$this->project->code}";
    }

    public function body(): string
    {
        $by = $this->changedBy ? " por {$this->changedBy}" : '';

        return "{$this->project->name} fue marcado en riesgo{$by}. Avance actual: {$this->project->progress}%.";
    }

    public function url(): string
    {
        return route('projects.show', $this->project);
    }

    public function icon(): string
    {
        return 'shield-exclamation';
    }

    public function level(): string
    {
        return 'warning';
    }
}
