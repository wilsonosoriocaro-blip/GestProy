<?php

namespace App\Actions\Projects;

use App\Actions\Projects\Concerns\AppliesStatusRules;
use App\Enums\ProgressMode;
use App\Enums\ProjectActivityEvent;
use App\Models\Project;
use App\Models\User;
use App\Services\Projects\ProjectActivityLogger;
use App\Services\Projects\ProjectCodeGenerator;
use Illuminate\Support\Facades\DB;

class CreateProject
{
    use AppliesStatusRules;

    public function __construct(
        private readonly ProjectCodeGenerator $codes,
        private readonly ProjectActivityLogger $activity,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated attributes (see ProjectForm).
     */
    public function handle(User $actor, array $data): Project
    {
        return DB::transaction(function () use ($actor, $data): Project {
            $project = new Project($data);
            $project->code = filled($data['code'] ?? null) ? $project->code : $this->codes->next();
            $project->created_by = $actor->id;
            $project->updated_by = $actor->id;

            if ($project->progress_mode === ProgressMode::Tasks) {
                // No tasks yet: calculated progress starts at zero.
                $project->progress = 0;
            }

            $this->applyStatusRules($project);
            $project->save();

            $this->activity->log($project, ProjectActivityEvent::ProjectCreated, "Proyecto {$project->code} creado", $project, null, [
                'code' => $project->code,
                'name' => $project->name,
                'owner_id' => $project->owner_id,
                'status_id' => $project->status_id,
            ]);

            return $project;
        });
    }
}
