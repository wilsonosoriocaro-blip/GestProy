<?php

namespace App\Services\Projects;

use App\Enums\ProgressMode;
use App\Enums\TaskStatusKind;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Calculated progress of a project: the weighted average of its top-level
 * tasks. Completed tasks count as 100%, cancelled tasks are left out and
 * subtasks do not count directly (they roll up into their parent task).
 * It runs as one aggregate query so it never loads the tasks in memory.
 */
class ProjectProgressCalculator
{
    public function calculate(Project $project): int
    {
        $row = DB::table('project_tasks')
            ->join('project_task_statuses', 'project_task_statuses.id', '=', 'project_tasks.status_id')
            ->where('project_tasks.project_id', $project->id)
            ->whereNull('project_tasks.parent_id')
            ->whereNull('project_tasks.deleted_at')
            ->where('project_task_statuses.kind', '!=', TaskStatusKind::Cancelled->value)
            ->selectRaw(
                'SUM(project_tasks.weight * CASE WHEN project_task_statuses.kind = ? THEN 100 ELSE project_tasks.progress END) AS weighted, SUM(project_tasks.weight) AS weights',
                [TaskStatusKind::Completed->value],
            )
            ->first();

        $weights = (int) ($row->weights ?? 0);

        if ($weights === 0) {
            return 0;
        }

        return (int) round((int) $row->weighted / $weights);
    }

    /**
     * Stores the calculated progress when the project uses task-based
     * progress. Returns true when the stored value changed.
     */
    public function sync(Project $project): bool
    {
        if ($project->progress_mode !== ProgressMode::Tasks) {
            return false;
        }

        $project->progress = $this->calculate($project);

        if (! $project->isDirty('progress')) {
            return false;
        }

        $project->saveQuietly();

        return true;
    }
}
