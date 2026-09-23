<?php

namespace App\Actions\Projects;

use App\Enums\ProjectActivityEvent;
use App\Models\Project;
use App\Models\User;
use App\Services\Projects\ProjectActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Soft delete: the project, its tasks and its log stay in the database and
 * can be recovered by an administrator.
 */
class DeleteProject
{
    public function __construct(private readonly ProjectActivityLogger $activity) {}

    public function handle(User $actor, Project $project): void
    {
        DB::transaction(function () use ($actor, $project): void {
            $this->activity->log($project, ProjectActivityEvent::ProjectDeleted, "Proyecto {$project->code} eliminado", $project);

            $project->forceFill(['updated_by' => $actor->id])->saveQuietly();
            $project->delete();
        });
    }
}
