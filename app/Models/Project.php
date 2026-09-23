<?php

namespace App\Models;

use App\Data\ScheduleSnapshot;
use App\Enums\ProgressMode;
use App\Services\Projects\ScheduleCalculator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string|null $objective
 * @property string|null $scope
 * @property int $category_id
 * @property int $status_id
 * @property int $priority_id
 * @property int $owner_id
 * @property CarbonImmutable|null $start_date
 * @property CarbonImmutable|null $due_date
 * @property CarbonImmutable|null $completed_at
 * @property int $progress
 * @property ProgressMode $progress_mode
 * @property string|null $budget
 * @property string|null $notes
 * @property CarbonImmutable|null $last_activity_at
 * @property CarbonImmutable|null $archived_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read ProjectCategory $category
 * @property-read ProjectStatus $status
 * @property-read ProjectPriority $priority
 * @property-read User $owner
 */
#[Fillable([
    'code', 'name', 'description', 'objective', 'scope',
    'category_id', 'status_id', 'priority_id', 'owner_id',
    'start_date', 'due_date', 'completed_at',
    'progress', 'progress_mode', 'budget', 'notes',
])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
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
            'progress_mode' => ProgressMode::class,
            'budget' => 'decimal:2',
            'last_activity_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ProjectCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProjectCategory::class, 'category_id');
    }

    /**
     * @return BelongsTo<ProjectStatus, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(ProjectStatus::class, 'status_id');
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
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Team members (the owner is not included).
     *
     * @return BelongsToMany<User, $this, ProjectMember, 'membership'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
            ->using(ProjectMember::class)
            ->as('membership')
            ->withPivot(['id', 'role'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<ProjectMember, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    /**
     * @return HasMany<ProjectTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class);
    }

    /**
     * Top-level tasks, the ones that make up the project's calculated progress.
     *
     * @return HasMany<ProjectTask, $this>
     */
    public function rootTasks(): HasMany
    {
        return $this->tasks()->whereNull('parent_id');
    }

    /**
     * @return HasMany<ProjectComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(ProjectComment::class);
    }

    /**
     * @return HasMany<ProjectActivityLog, $this>
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ProjectActivityLog::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function notArchived(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function archived(Builder $query): void
    {
        $query->whereNotNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Whether the user owns the project or belongs to its team.
     */
    public function isParticipant(User $user): bool
    {
        return $this->owner_id === $user->id
            || $this->memberships()->where('user_id', $user->id)->exists();
    }

    /**
     * Schedule metrics as of $today. Requires the status relation; eager load
     * it when listing projects to avoid N+1 queries.
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
