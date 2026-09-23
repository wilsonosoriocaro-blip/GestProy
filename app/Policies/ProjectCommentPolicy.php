<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\ProjectComment;
use App\Models\User;

/**
 * Project log ("bitácora"): the team writes it, whoever can see the
 * project reads it. Authors edit their own entries; the project owner and
 * leaders moderate and highlight. Archived projects accept no new entries.
 */
class ProjectCommentPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $this->projects->view($user, $project);
    }

    public function create(User $user, Project $project): bool
    {
        return ! $project->isArchived()
            && ($project->isParticipant($user) || $user->can(Permission::ProjectsUpdateAll->value));
    }

    public function update(User $user, ProjectComment $comment): bool
    {
        return ! $comment->project->isArchived() && $comment->user_id === $user->id;
    }

    public function delete(User $user, ProjectComment $comment): bool
    {
        return ! $comment->project->isArchived()
            && ($comment->user_id === $user->id || $this->moderates($user, $comment->project));
    }

    /**
     * Mark or unmark an entry as a milestone of the project.
     */
    public function highlight(User $user, Project $project): bool
    {
        return ! $project->isArchived() && $this->moderates($user, $project);
    }

    private function moderates(User $user, Project $project): bool
    {
        return $project->owner_id === $user->id || $user->can(Permission::ProjectsUpdateAll->value);
    }
}
