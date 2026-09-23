<?php

namespace App\Services\Projects;

use App\Enums\ProjectActivityEvent;
use App\Models\Project;
use App\Models\ProjectActivityLog;
use App\Models\ProjectTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Single entry point to write the project timeline / audit trail. It also
 * refreshes projects.last_activity_at, used to spot stale projects.
 */
class ProjectActivityLogger
{
    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     * @param  User|null  $actor  Who did it; defaults to the authenticated user.
     */
    public function log(
        Project $project,
        ProjectActivityEvent $event,
        ?string $description = null,
        ?Model $subject = null,
        ?array $old = null,
        ?array $new = null,
        ?User $actor = null,
    ): ProjectActivityLog {
        $log = new ProjectActivityLog([
            'project_id' => $project->id,
            'task_id' => $subject instanceof ProjectTask ? $subject->id : null,
            // Explicit actor when known (seeders, jobs); otherwise the signed-in user.
            'user_id' => $actor->id ?? $this->request->user()?->id,
            'event' => $event,
            'old_values' => $old,
            'new_values' => $new,
            'description' => $description,
            'ip_address' => $this->request->ip(),
        ]);

        if ($subject !== null) {
            $log->subject()->associate($subject);
        }

        $log->save();

        $project->forceFill(['last_activity_at' => $log->created_at])->saveQuietly();

        return $log;
    }
}
