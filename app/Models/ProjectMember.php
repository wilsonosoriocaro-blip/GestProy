<?php

namespace App\Models;

use App\Enums\ProjectMemberRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $id
 * @property int $project_id
 * @property int $user_id
 * @property ProjectMemberRole $role
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Project $project
 * @property-read User $user
 */
#[Fillable(['project_id', 'user_id', 'role'])]
class ProjectMember extends Pivot
{
    protected $table = 'project_members';

    public $incrementing = true;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => ProjectMemberRole::class,
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
