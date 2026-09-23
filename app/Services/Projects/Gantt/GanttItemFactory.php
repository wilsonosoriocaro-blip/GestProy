<?php

namespace App\Services\Projects\Gantt;

use App\Models\Project;
use App\Models\ProjectTask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Maps projects and tasks to timeline rows. Tasks need status, assignee and
 * dependencies loaded; projects need status and owner.
 */
class GanttItemFactory
{
    /**
     * @param  iterable<ProjectTask>  $tasks
     * @return list<GanttItem>
     */
    public function fromTasks(iterable $tasks, ?CarbonImmutable $today = null): array
    {
        $items = [];

        foreach ($tasks as $task) {
            $items[] = new GanttItem(
                key: 'task-'.$task->id,
                label: $task->name,
                sublabel: $task->assignee->name ?? 'Sin asignar',
                href: null,
                start: $task->start_date,
                end: $task->due_date,
                progress: $task->progress,
                health: $task->schedule($today)->health,
                isOpen: ! $task->status->kind->lifecycle()->isClosed(),
                dependsOn: array_values($task->dependencies->map(fn (ProjectTask $dependency): string => 'task-'.$dependency->id)->all()),
            );
        }

        return $items;
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @return list<GanttItem>
     */
    public function fromProjects(Collection $projects, ?CarbonImmutable $today = null): array
    {
        return array_values($projects->map(fn (Project $project) => new GanttItem(
            key: 'project-'.$project->id,
            label: $project->name,
            sublabel: $project->code.' · '.$project->owner->name,
            href: route('projects.show', $project),
            start: $project->start_date,
            end: $project->due_date,
            progress: $project->progress,
            health: $project->schedule($today)->health,
            isOpen: ! $project->status->kind->lifecycle()->isClosed(),
        ))->all());
    }
}
