<?php

namespace App\Queries\Projects;

use App\Enums\TaskStatusKind;
use App\Models\Project;
use App\Models\ProjectTask;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filtered, sorted query behind the task list of a project.
 */
class TaskIndexQuery
{
    public const SORTABLE = [
        'name' => 'project_tasks.name',
        'due_date' => 'project_tasks.due_date',
        'progress' => 'project_tasks.progress',
        'priority' => 'project_priorities.level',
    ];

    /**
     * @param  array{
     *     search?: string|null,
     *     status?: int|string|null,
     *     assignee?: int|string|null,
     *     kind?: string|null,
     *     signal?: string|null,
     *     hide_closed?: bool,
     *     sort?: string|null,
     *     direction?: string|null,
     * }  $filters
     * @return Builder<ProjectTask>
     */
    public function build(Project $project, array $filters, ?TaskSignals $signals = null): Builder
    {
        $query = ProjectTask::query()
            ->select('project_tasks.*')
            ->join('project_priorities', 'project_priorities.id', '=', 'project_tasks.priority_id')
            ->where('project_tasks.project_id', $project->id)
            ->with(['status', 'priority', 'assignee:id,name', 'dependencies:id,name'])
            ->withCount('dependencies');

        if (filled($term = trim((string) ($filters['search'] ?? '')))) {
            $like = '%'.addcslashes($term, '\\%_').'%';
            $query->whereAny(['project_tasks.name', 'project_tasks.description'], 'ilike', $like);
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('project_tasks.status_id', (int) $filters['status']);
        }

        if (($filters['assignee'] ?? null) === 'none') {
            $query->whereNull('project_tasks.assignee_id');
        } elseif (filled($filters['assignee'] ?? null)) {
            $query->where('project_tasks.assignee_id', (int) $filters['assignee']);
        }

        if (($kind = TaskStatusKind::tryFrom((string) ($filters['kind'] ?? ''))) !== null) {
            $query->whereHas('status', fn (Builder $q) => $q->where('kind', $kind->value));
        }

        if (isset(TaskSignals::LABELS[$filters['signal'] ?? ''])) {
            ($signals ?? TaskSignals::make())->apply($query, (string) $filters['signal']);
        }

        if (! empty($filters['hide_closed'])) {
            $query->whereHas('status', fn (Builder $q) => $q->whereNotIn('kind', [
                TaskStatusKind::Completed->value,
                TaskStatusKind::Cancelled->value,
            ]));
        }

        $sort = self::SORTABLE[$filters['sort'] ?? ''] ?? null;
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        if ($sort !== null) {
            $query->orderByRaw("{$sort} {$direction} NULLS LAST");
        } else {
            $query->orderBy('project_tasks.sort_order')->orderByRaw('project_tasks.due_date asc NULLS LAST');
        }

        return $query->orderBy('project_tasks.id');
    }
}
