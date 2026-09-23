<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;

/**
 * Tasks follow their project: whoever manages the project manages its
 * tasks; the assignee may report progress on their own task. Tasks of an
 * archived project are read-only.
 */
class ProjectTaskPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $this->projects->view($user, $project);
    }

    public function view(User $user, ProjectTask $task): bool
    {
        return $this->projects->view($user, $task->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $this->manages($user, $project);
    }

    /**
     * Full edit: name, dates, assignee, dependencies, weight…
     */
    public function update(User $user, ProjectTask $task): bool
    {
        return $this->manages($user, $task->project);
    }

    /**
     * Progress report: status, progress and notes only.
     */
    public function updateProgress(User $user, ProjectTask $task): bool
    {
        return $this->update($user, $task)
            || (! $task->project->isArchived() && $task->assignee_id === $user->id);
    }

    public function delete(User $user, ProjectTask $task): bool
    {
        return $this->manages($user, $task->project);
    }

    private function manages(User $user, Project $project): bool
    {
        if ($project->isArchived()) {
            return false;
        }

        return $user->can(Permission::TasksManageAll->value)
            || $project->owner_id === $user->id;
    }
}
