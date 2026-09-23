<?php

namespace App\Queries\Projects;

use App\Enums\ProjectStatusKind;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds the filtered, sorted query behind the project list. Everything is
 * resolved in SQL so the list can be paginated; nothing is loaded in memory.
 */
class ProjectIndexQuery
{
    /** Column the user may sort by => SQL expression. */
    public const SORTABLE = [
        'code' => 'projects.code',
        'name' => 'projects.name',
        'due_date' => 'projects.due_date',
        'progress' => 'projects.progress',
        'priority' => 'project_priorities.level',
        'updated_at' => 'projects.updated_at',
    ];

    /** Progress bands offered by the filter => [min, max] inclusive. */
    public const PROGRESS_BANDS = [
        '0-24' => [0, 24],
        '25-49' => [25, 49],
        '50-74' => [50, 74],
        '75-99' => [75, 99],
        '100' => [100, 100],
    ];

    /**
     * @param  array{
     *     search?: string|null,
     *     status?: int|string|null,
     *     owner?: int|string|null,
     *     priority?: int|string|null,
     *     category?: int|string|null,
     *     due_from?: string|null,
     *     due_to?: string|null,
     *     progress?: string|null,
     *     overdue?: bool,
     *     at_risk?: bool,
     *     archived?: bool,
     *     sort?: string|null,
     *     direction?: string|null,
     * }  $filters
     * @return Builder<Project>
     */
    public function build(User $user, array $filters, ?CarbonImmutable $today = null): Builder
    {
        $today ??= CarbonImmutable::today();

        $query = Project::query()
            ->select('projects.*')
            ->join('project_priorities', 'project_priorities.id', '=', 'projects.priority_id')
            ->visibleTo($user)
            ->with(['status', 'priority', 'category', 'owner']);

        empty($filters['archived']) ? $query->notArchived() : $query->archived();

        if (filled($term = trim((string) ($filters['search'] ?? '')))) {
            $like = '%'.addcslashes($term, '\\%_').'%';

            $query->where(fn (Builder $query) => $query
                ->whereAny(['projects.code', 'projects.name', 'projects.description'], 'ilike', $like)
                ->orWhereHas('owner', fn (Builder $query) => $query->where('name', 'ilike', $like)));
        }

        foreach (['status' => 'status_id', 'owner' => 'owner_id', 'priority' => 'priority_id', 'category' => 'category_id'] as $filter => $column) {
            if (filled($filters[$filter] ?? null)) {
                $query->where("projects.{$column}", (int) $filters[$filter]);
            }
        }

        if (filled($filters['due_from'] ?? null)) {
            $query->whereDate('projects.due_date', '>=', (string) $filters['due_from']);
        }

        if (filled($filters['due_to'] ?? null)) {
            $query->whereDate('projects.due_date', '<=', (string) $filters['due_to']);
        }

        if (isset(self::PROGRESS_BANDS[$filters['progress'] ?? ''])) {
            $query->whereBetween('projects.progress', self::PROGRESS_BANDS[$filters['progress']]);
        }

        if (! empty($filters['overdue'])) {
            $this->whereOverdue($query, $today);
        }

        if (! empty($filters['at_risk'])) {
            // Declared at risk by a person, or objectively past the due date.
            $query->where(fn (Builder $query) => $query
                ->whereHas('status', fn (Builder $query) => $query->where('kind', ProjectStatusKind::AtRisk->value))
                ->orWhere(fn (Builder $query) => $this->whereOverdue($query, $today)));
        }

        $sort = self::SORTABLE[$filters['sort'] ?? ''] ?? null;
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        if ($sort !== null) {
            $query->orderByRaw("{$sort} {$direction} NULLS LAST");
        } else {
            // Default: what needs attention first, i.e. the closest due dates.
            $query->orderByRaw('projects.due_date asc NULLS LAST');
        }

        return $query->orderBy('projects.id');
    }

    /**
     * Open projects whose due date already passed.
     *
     * @param  Builder<Project>  $query
     */
    public function whereOverdue(Builder $query, CarbonImmutable $today): void
    {
        $query->whereDate('projects.due_date', '<', $today)
            ->whereHas('status', fn (Builder $query) => $query->whereIn('kind', [
                ProjectStatusKind::Planned->value,
                ProjectStatusKind::Active->value,
                ProjectStatusKind::Paused->value,
                ProjectStatusKind::AtRisk->value,
            ]));
    }
}
