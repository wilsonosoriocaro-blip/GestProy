<?php

namespace App\Actions\Projects;

use App\Enums\ProjectActivityEvent;
use App\Enums\ProjectMemberRole;
use App\Models\Project;
use App\Models\User;
use App\Services\Projects\ProjectActivityLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ManageProjectMembers
{
    public function __construct(private readonly ProjectActivityLogger $activity) {}

    /**
     * Adds the user to the team, or changes their role if already there.
     */
    public function add(Project $project, User $user, ProjectMemberRole $role): void
    {
        if ($user->id === $project->owner_id) {
            throw new InvalidArgumentException('El responsable del proyecto ya hace parte del equipo.');
        }

        DB::transaction(function () use ($project, $user, $role): void {
            $project->members()->syncWithoutDetaching([$user->id => ['role' => $role->value]]);

            $this->activity->log($project, ProjectActivityEvent::MemberAdded, "{$user->name} ({$role->label()})", $project,
                null, ['user_id' => $user->id, 'role' => $role->value],
            );
        });
    }

    public function remove(Project $project, User $user): void
    {
        DB::transaction(function () use ($project, $user): void {
            if ($project->members()->detach($user->id) === 0) {
                return;
            }

            $this->activity->log($project, ProjectActivityEvent::MemberRemoved, $user->name, $project,
                ['user_id' => $user->id], null,
            );
        });
    }
}
