<?php

namespace App\Actions\Tasks;

use App\Actions\Projects\RefreshProjectProgress;
use App\Actions\Tasks\Concerns\AppliesTaskStatusRules;
use App\Actions\Tasks\Concerns\LogsTaskChanges;
use App\Enums\ProjectActivityEvent;
use App\Models\ProjectTask;
use App\Models\User;
use App\Services\Projects\ProjectActivityLogger;
use App\Services\Projects\TaskDependencyGuard;
use Illuminate\Support\Facades\DB;

/**
 * Full edit of a task by whoever manages the project.
 */
class UpdateTask
{
    use AppliesTaskStatusRules, LogsTaskChanges;

    public function __construct(
        private readonly TaskDependencyGuard $dependencies,
        private readonly ProjectActivityLogger $activity,
        private readonly RefreshProjectProgress $progress,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated attributes (see TaskForm).
     * @param  list<int>  $dependsOn
     */
    public function handle(User $actor, ProjectTask $task, array $data, array $dependsOn = []): ProjectTask
    {
        $this->dependencies->validate($task->project_id, $task->id, $dependsOn, 'form.dependencies');

        return DB::transaction(function () use ($actor, $task, $data, $dependsOn): ProjectTask {
            $task->fill($data);
            $this->applyStatusRules($task);

            $dependencyChanges = $task->dependencies()->sync($dependsOn);
            $dependenciesChanged = array_filter($dependencyChanges) !== [];

            if ($task->isDirty()) {
                $task->updated_by = $actor->id;
                $changes = $this->taskChanges($task);
                $task->save();
                $this->logTaskChanges($this->activity, $task, $changes);
            }

            if ($dependenciesChanged) {
                $this->activity->log($task->project, ProjectActivityEvent::TaskUpdated, "{$task->name}: dependencias actualizadas", $task,
                    ['dependencies' => $dependencyChanges['detached']],
                    ['dependencies' => $dependsOn],
                );
            }

            $this->progress->handle($task->project);

            return $task;
        });
    }
}
