<?php

namespace App\Actions\Tasks\Concerns;

use App\Enums\TaskStatusKind;
use App\Models\ProjectTask;
use App\Models\ProjectTaskStatus;

trait AppliesTaskStatusRules
{
    /**
     * A completed task is 100% done and has a completion date; any other
     * status clears the completion date.
     */
    protected function applyStatusRules(ProjectTask $task): void
    {
        $kind = ProjectTaskStatus::query()->find($task->status_id)?->kind;

        if ($kind === TaskStatusKind::Completed) {
            $task->completed_at ??= today();
            $task->progress = 100;

            return;
        }

        $task->completed_at = null;
    }
}
