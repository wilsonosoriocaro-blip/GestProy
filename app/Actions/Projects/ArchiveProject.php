<?php

namespace App\Actions\Projects;

use App\Enums\ProjectActivityEvent;
use App\Models\Project;
use App\Models\User;
use App\Services\Projects\ProjectActivityLogger;
use Illuminate\Support\Facades\DB;

class ArchiveProject
{
    public function __construct(private readonly ProjectActivityLogger $activity) {}

    public function archive(User $actor, Project $project): void
    {
        DB::transaction(function () use ($actor, $project): void {
            $project->forceFill(['archived_at' => now(), 'updated_by' => $actor->id])->save();

            $this->activity->log($project, ProjectActivityEvent::ProjectArchived, 'Proyecto archivado', $project);
        });
    }

    public function restore(User $actor, Project $project): void
    {
        DB::transaction(function () use ($actor, $project): void {
            $project->forceFill(['archived_at' => null, 'updated_by' => $actor->id])->save();

            $this->activity->log($project, ProjectActivityEvent::ProjectRestored, 'Proyecto restaurado', $project);
        });
    }
}
