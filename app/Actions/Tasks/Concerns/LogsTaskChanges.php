<?php

namespace App\Actions\Tasks\Concerns;

use App\Enums\ProjectActivityEvent;
use App\Enums\TaskStatusKind;
use App\Models\ProjectTask;
use App\Models\ProjectTaskStatus;
use App\Models\User;
use App\Services\Projects\ProjectActivityLogger;
use Carbon\CarbonInterface;

/**
 * Turns the dirty attributes of a task into timeline entries: assignment,
 * status (or completion) and a generic "updated" entry for the rest.
 */
trait LogsTaskChanges
{
    /**
     * Old and new values of every dirty attribute. Call before save().
     *
     * @return array<string, array{mixed, mixed}>
     */
    protected function taskChanges(ProjectTask $task): array
    {
        $changes = [];

        foreach (array_keys($task->getDirty()) as $attribute) {
            if ($attribute === 'updated_by') {
                continue;
            }

            $changes[$attribute] = [
                $this->plain($task->getOriginal($attribute)),
                $this->plain($task->getAttribute($attribute)),
            ];
        }

        return $changes;
    }

    /**
     * @param  array<string, array{mixed, mixed}>  $changes
     */
    protected function logTaskChanges(ProjectActivityLogger $activity, ProjectTask $task, array $changes): void
    {
        $project = $task->project;

        if (array_key_exists('assignee_id', $changes)) {
            [$from, $to] = $changes['assignee_id'];
            $names = User::query()->whereKey(array_filter([$from, $to]))->pluck('name', 'id');

            $activity->log($project, ProjectActivityEvent::TaskAssigned,
                "{$task->name}: ".($names[$from] ?? 'sin asignar').' → '.($names[$to] ?? 'sin asignar'), $task,
                ['assignee_id' => $from, 'assignee' => $names[$from] ?? null],
                ['assignee_id' => $to, 'assignee' => $names[$to] ?? null],
            );
        }

        if (array_key_exists('status_id', $changes)) {
            [$from, $to] = $changes['status_id'];
            $statuses = ProjectTaskStatus::query()->whereKey([$from, $to])->get()->keyBy('id');
            $completed = $statuses[$to]?->kind === TaskStatusKind::Completed;

            $activity->log($project, $completed ? ProjectActivityEvent::TaskCompleted : ProjectActivityEvent::TaskStatusChanged,
                "{$task->name}: {$statuses[$from]?->name} → {$statuses[$to]?->name}", $task,
                ['status_id' => $from, 'status' => $statuses[$from]?->name],
                ['status_id' => $to, 'status' => $statuses[$to]?->name],
            );
        }

        $other = array_diff_key($changes, array_flip(['assignee_id', 'status_id', 'completed_at']));

        if ($other !== []) {
            $activity->log($project, ProjectActivityEvent::TaskUpdated, $task->name, $task,
                array_map(fn (array $pair) => $pair[0], $other),
                array_map(fn (array $pair) => $pair[1], $other),
            );
        }
    }

    private function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof CarbonInterface => $value->toDateString(),
            $value instanceof \BackedEnum => $value->value,
            default => $value,
        };
    }
}
