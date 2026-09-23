<?php

namespace App\Models;

use App\Enums\ProjectActivityEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only record of what happened in a project and who did it. Serves
 * as the project timeline and as the audit trail (old and new values).
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $task_id
 * @property int|null $user_id
 * @property ProjectActivityEvent $event
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property string|null $description
 * @property string|null $ip_address
 * @property CarbonImmutable $created_at
 * @property-read Project $project
 * @property-read ProjectTask|null $task
 * @property-read User|null $user
 */
#[Fillable([
    'project_id', 'task_id', 'user_id', 'event', 'subject_type', 'subject_id',
    'old_values', 'new_values', 'description', 'ip_address',
])]
class ProjectActivityLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => ProjectActivityEvent::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<ProjectTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'task_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
