<?php

namespace App\Actions\Projects\Concerns;

use App\Enums\ProgressMode;
use App\Enums\ProjectStatusKind;
use App\Models\Project;
use App\Models\ProjectStatus;

trait AppliesStatusRules
{
    /**
     * Keeps completion data consistent with the status: a completed project
     * always has a completion date (and 100% when progress is manual); any
     * other status clears the completion date.
     */
    protected function applyStatusRules(Project $project): void
    {
        $kind = ProjectStatus::query()->find($project->status_id)?->kind;

        if ($kind === ProjectStatusKind::Completed) {
            $project->completed_at ??= today();

            if ($project->progress_mode === ProgressMode::Manual) {
                $project->progress = 100;
            }

            return;
        }

        $project->completed_at = null;
    }
}
