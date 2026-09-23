<?php

namespace App\Actions\Projects;

use App\Enums\ProjectActivityEvent;
use App\Models\Project;
use App\Services\Projects\ProjectActivityLogger;
use App\Services\Projects\ProjectProgressCalculator;

/**
 * Recalculates a task-based project's progress after its tasks change and
 * leaves a timeline entry when the value moves.
 */
class RefreshProjectProgress
{
    public function __construct(
        private readonly ProjectProgressCalculator $calculator,
        private readonly ProjectActivityLogger $activity,
    ) {}

    public function handle(Project $project): void
    {
        $before = $project->progress;

        if (! $this->calculator->sync($project)) {
            return;
        }

        $this->activity->log($project, ProjectActivityEvent::ProgressChanged,
            "Avance recalculado por tareas: {$before}% → {$project->progress}%", $project,
            ['progress' => $before], ['progress' => $project->progress],
        );
    }
}
