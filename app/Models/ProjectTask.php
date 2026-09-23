<?php

namespace App\Models;

use App\Data\ScheduleSnapshot;
use App\Services\Projects\ScheduleCalculator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\ProjectTaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $parent_id
 * @property string $name
 * @property string|null $description
 * @property int|null $assignee_id
 * @property int $status_id
 * @property int $priority_id
 * @property CarbonImmutable|null $start_date
 * @property CarbonImmutable|null $due_date
 * @property CarbonImmutable|null $completed_at
 * @property int $progress
 * @property int $weight
 * @property int $sort_order
 * @property string|null $notes
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Project $project
 * @property-read ProjectTask|null $parent
 * @property-read User|null $assignee
 * @property-read ProjectTaskStatus $status
 * @property-read ProjectPriority $priority
 */
#[Fillable([
    'parent_id', 'name', 'description', 'assignee_id', 'status_id', 'priority_id',
    'start_date', 'due_date', 'completed_at', 'progress', 'weight', 'sort_order', 'notes',
])]
class ProjectTask extends Model
{
    /** @use HasFactory<ProjectTaskFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'due_date' => 'date',
            'completed_at' => 'date',
            'progress' => 'integer',
            'weight' => 'integer',
            'sort_order' => 'integer',
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
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'parent_id');
    }

    /**
     * @return HasMany<ProjectTask, $this>
     */
    public function subtasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class, 'parent_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return BelongsTo<ProjectTaskStatus, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(ProjectTaskStatus::class, 'status_id');
    }

    /**
     * @return BelongsTo<ProjectPriority, $this>
     */
    public function priority(): BelongsTo
    {
        return $this->belongsTo(ProjectPriority::class, 'priority_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Tasks that must progress before this one (predecessors).
     *
     * @return BelongsToMany<ProjectTask, $this>
     */
    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(ProjectTask::class, 'project_task_dependencies', 'task_id', 'depends_on_id')
            ->withPivot('type')
            ->withTimestamps();
    }

    /**
     * Tasks waiting on this one (successors).
     *
     * @return BelongsToMany<ProjectTask, $this>
     */
    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(ProjectTask::class, 'project_task_dependencies', 'depends_on_id', 'task_id')
            ->withPivot('type')
            ->withTimestamps();
    }

    /**
     * @return HasMany<ProjectComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(ProjectComment::class, 'task_id');
    }

    /**
     * Schedule metrics as of $today. Requires the status relation.
     */
    public function schedule(?CarbonInterface $today = null): ScheduleSnapshot
    {
        return app(ScheduleCalculator::class)->calculate(
            $this->start_date,
            $this->due_date,
            $this->completed_at,
            $this->progress,
            $this->status->kind->lifecycle(),
            $today,
        );
    }
}
