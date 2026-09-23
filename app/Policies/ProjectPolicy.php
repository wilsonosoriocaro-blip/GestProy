<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\User;

/**
 * Global abilities come from spatie permissions; row-level access comes
 * from being the owner or a team member of the project.
 */
class ProjectPolicy
{
    /**
     * Anyone signed in can open the list: Project::visibleTo() limits the rows.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Project $project): bool
    {
        return $user->can(Permission::ProjectsViewAll->value)
            || $project->isParticipant($user);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ProjectsCreate->value);
    }

    /**
     * Archived projects are read-only until they are restored.
     */
    public function update(User $user, Project $project): bool
    {
        if ($project->isArchived()) {
            return false;
        }

        return $user->can(Permission::ProjectsUpdateAll->value)
            || $project->owner_id === $user->id;
    }

    /**
     * Managing the team follows the same rule as editing the project.
     */
    public function manageMembers(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }

    public function archive(User $user, Project $project): bool
    {
        return ! $project->isArchived()
            && $user->can(Permission::ProjectsArchive->value);
    }

    public function restore(User $user, Project $project): bool
    {
        return $project->isArchived()
            && $user->can(Permission::ProjectsArchive->value);
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->can(Permission::ProjectsDelete->value);
    }
}
