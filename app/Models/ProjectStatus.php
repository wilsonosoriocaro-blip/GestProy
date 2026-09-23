<?php

namespace App\Models;

use App\Enums\ProjectStatusKind;
use Database\Factories\ProjectStatusFactory;
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
 * @property ProjectStatusKind $kind
 * @property string $color
 * @property string|null $icon
 * @property bool $is_default
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'kind', 'color', 'icon', 'is_default', 'is_active', 'sort_order'])]
class ProjectStatus extends Model
{
    /** @use HasFactory<ProjectStatusFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ProjectStatusKind::class,
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'status_id');
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
     * @param  ProjectStatusKind|list<ProjectStatusKind>  $kinds
     */
    #[Scope]
    protected function ofKind(Builder $query, ProjectStatusKind|array $kinds): void
    {
        $kinds = is_array($kinds) ? $kinds : [$kinds];

        $query->whereIn('kind', array_map(fn (ProjectStatusKind $kind) => $kind->value, $kinds));
    }
}
