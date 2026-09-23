<?php

namespace App\Models;

use App\Enums\TaskStatusKind;
use Database\Factories\ProjectTaskStatusFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property TaskStatusKind $kind
 * @property string $color
 * @property string|null $icon
 * @property bool $is_default
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'kind', 'color', 'icon', 'is_default', 'is_active', 'sort_order'])]
class ProjectTaskStatus extends Model
{
    /** @use HasFactory<ProjectTaskStatusFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => TaskStatusKind::class,
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<ProjectTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class, 'status_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * @param  Builder<self>  $query
     * @param  TaskStatusKind|list<TaskStatusKind>  $kinds
     */
    #[Scope]
    protected function ofKind(Builder $query, TaskStatusKind|array $kinds): void
    {
        $kinds = is_array($kinds) ? $kinds : [$kinds];

        $query->whereIn('kind', array_map(fn (TaskStatusKind $kind) => $kind->value, $kinds));
    }
}
