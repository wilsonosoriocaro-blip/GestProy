<?php

namespace App\Actions\Tasks;

use App\Actions\Projects\RefreshProjectProgress;
use App\Actions\Tasks\Concerns\AppliesTaskStatusRules;
use App\Enums\ProjectActivityEvent;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use App\Services\Projects\ProjectActivityLogger;
use App\Services\Projects\TaskDependencyGuard;
use Illuminate\Support\Facades\DB;

class CreateTask
{
    use AppliesTaskStatusRules;

    public function __construct(
        private readonly TaskDependencyGuard $dependencies,
        private readonly ProjectActivityLogger $activity,
        private readonly RefreshProjectProgress $progress,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated attributes (see TaskForm).
     * @param  list<int>  $dependsOn
     */
    public function handle(User $actor, Project $project, array $data, array $dependsOn = []): ProjectTask
    {
        $this->dependencies->validate($project->id, null, $dependsOn, 'form.dependencies');

        return DB::transaction(function () use ($actor, $project, $data, $dependsOn): ProjectTask {
            $task = new ProjectTask($data);
            $task->project()->associate($project);
            $task->created_by = $actor->id;
            $task->updated_by = $actor->id;
            $task->sort_order = (int) $project->tasks()->withTrashed()->max('sort_order') + 1;

            $this->applyStatusRules($task);
            $task->save();
            $task->dependencies()->sync($dependsOn);

            $this->activity->log($project, ProjectActivityEvent::TaskCreated, $task->name, $task, null, [
                'name' => $task->name,
                'assignee_id' => $task->assignee_id,
                'due_date' => $task->due_date?->toDateString(),
            ]);

            if ($task->assignee_id !== null) {
                $this->activity->log($project, ProjectActivityEvent::TaskAssigned,
                    "{$task->name}: asignada a {$task->assignee?->name}", $task,
                    null, ['assignee_id' => $task->assignee_id],
                );
            }

            $this->progress->handle($project);

            return $task;
        });
    }
}
