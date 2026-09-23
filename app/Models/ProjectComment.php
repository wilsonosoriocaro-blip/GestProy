<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ProjectCommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Follow-up entry of the project log ("bitácora").
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $task_id
 * @property int|null $user_id
 * @property string $body
 * @property bool $is_highlighted
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Project $project
 * @property-read ProjectTask|null $task
 * @property-read User|null $user
 */
#[Fillable(['task_id', 'body', 'is_highlighted'])]
class ProjectComment extends Model
{
    /** @use HasFactory<ProjectCommentFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_highlighted' => 'boolean',
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
}
