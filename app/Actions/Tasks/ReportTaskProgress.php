<?php

namespace App\Actions\Tasks;

use App\Actions\Projects\RefreshProjectProgress;
use App\Actions\Tasks\Concerns\AppliesTaskStatusRules;
use App\Actions\Tasks\Concerns\LogsTaskChanges;
use App\Models\ProjectTask;
use App\Models\User;
use App\Services\Projects\ProjectActivityLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Progress report by the assignee: only status, progress and notes change,
 * whatever else the payload carries.
 */
class ReportTaskProgress
{
    use AppliesTaskStatusRules, LogsTaskChanges;

    public const FIELDS = ['status_id', 'progress', 'notes', 'completed_at'];

    public function __construct(
        private readonly ProjectActivityLogger $activity,
        private readonly RefreshProjectProgress $progress,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, ProjectTask $task, array $data): ProjectTask
    {
        return DB::transaction(function () use ($actor, $task, $data): ProjectTask {
            $task->fill(Arr::only($data, self::FIELDS));
            $this->applyStatusRules($task);

            if (! $task->isDirty()) {
                return $task;
            }

            $task->updated_by = $actor->id;
            $changes = $this->taskChanges($task);
            $task->save();

            $this->logTaskChanges($this->activity, $task, $changes);
            $this->progress->handle($task->project);

            return $task;
        });
    }
}
