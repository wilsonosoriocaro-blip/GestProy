<?php

namespace App\Actions\Tasks;

use App\Actions\Projects\RefreshProjectProgress;
use App\Enums\ProjectActivityEvent;
use App\Models\ProjectTask;
use App\Models\User;
use App\Services\Projects\ProjectActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Soft deletes a task and its subtasks, then recalculates the project.
 */
class DeleteTask
{
    public function __construct(
        private readonly ProjectActivityLogger $activity,
        private readonly RefreshProjectProgress $progress,
    ) {}

    public function handle(User $actor, ProjectTask $task): void
    {
        DB::transaction(function () use ($actor, $task): void {
            $project = $task->project;

            $this->activity->log($project, ProjectActivityEvent::TaskDeleted, $task->name, $task);

            $task->subtasks()->update(['deleted_at' => now(), 'updated_by' => $actor->id]);
            $task->forceFill(['updated_by' => $actor->id])->saveQuietly();
            $task->delete();

            $this->progress->handle($project);
        });
    }
}
