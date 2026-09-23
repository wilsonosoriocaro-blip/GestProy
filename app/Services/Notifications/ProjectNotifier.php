<?php

namespace App\Services\Notifications;

use App\Enums\Role;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use App\Notifications\Projects\ProjectAtRiskNotification;
use App\Notifications\Projects\ProjectOwnerAssignedNotification;
use App\Notifications\Projects\TaskAssignedNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Decides who hears about what. Actions call it after a change; it never
 * notifies the person who made the change nor deactivated users.
 */
class ProjectNotifier
{
    public function taskAssigned(ProjectTask $task, ?User $actor = null): void
    {
        $assignee = $task->assignee;

        if ($assignee === null || ! $assignee->isActive() || $assignee->is($actor)) {
            return;
        }

        $assignee->notify(new TaskAssignedNotification($task, $actor?->name));
    }

    public function projectOwnerAssigned(Project $project, ?User $actor = null): void
    {
        $owner = $project->owner;

        if (! $owner->isActive() || $owner->is($actor)) {
            return;
        }

        $owner->notify(new ProjectOwnerAssignedNotification($project, $actor?->name));
    }

    /**
     * The owner and every leader, plus the team's Teams channel when configured.
     */
    public function projectAtRisk(Project $project, ?User $actor = null): void
    {
        $notification = new ProjectAtRiskNotification($project, $actor?->name);

        $recipients = User::query()->active()->role(Role::Leader->value)->get()
            ->push($project->owner)
            ->unique('id')
            ->filter(fn (User $user) => $user->isActive() && ! $user->is($actor));

        Notification::send($recipients, $notification);

        if (filled($url = config('projects.notifications.teams_webhook_url'))) {
            Notification::route('teams', $url)->notify(new ProjectAtRiskNotification($project, $actor?->name));
        }
    }
}
