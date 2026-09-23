<?php

namespace App\Queries\Projects;

use App\Data\ScheduleSnapshot;
use App\Enums\Lifecycle;
use App\Enums\ProjectStatusKind;
use App\Enums\ScheduleHealth;
use App\Models\Project;
use App\Models\ProjectPriority;
use App\Models\ProjectStatus;
use App\Models\ProjectTask;
use App\Models\User;
use App\Support\Calendar\BusinessCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Everything the executive dashboard shows, as plain arrays so the result
 * can be cached briefly. Scope: non-archived projects the user can see.
 *
 * Project numbers need the business-day schedule of each project, which is
 * computed in PHP over one lightweight query; task numbers are SQL aggregates.
 */
class PortfolioDashboardQuery
{
    public const CACHE_SECONDS = 60;

    private const UPCOMING_CALENDAR_DAYS = 30;

    private const LIST_LIMIT = 5;

    public function __construct(private readonly BusinessCalendar $calendar) {}

    /**
     * @param  array{category?: int|string|null, owner?: int|string|null}  $filters
     * @return array<string, mixed>
     */
    public function get(User $user, array $filters = [], ?CarbonImmutable $today = null, bool $fresh = false): array
    {
        $today ??= CarbonImmutable::today();
        $key = $this->cacheKey($user, $filters, $today);

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, self::CACHE_SECONDS, fn (): array => $this->build($user, $filters, $today));
    }

    /**
     * @param  array{category?: int|string|null, owner?: int|string|null}  $filters
     */
    public function cacheKey(User $user, array $filters, CarbonImmutable $today): string
    {
        return 'projects:dashboard:'.$user->id.':'.$today->toDateString().':'.md5((string) json_encode([
            'category' => (string) ($filters['category'] ?? ''),
            'owner' => (string) ($filters['owner'] ?? ''),
        ]));
    }

    /**
     * @param  array{category?: int|string|null, owner?: int|string|null}  $filters
     * @return array<string, mixed>
     */
    public function build(User $user, array $filters, CarbonImmutable $today): array
    {
        $projects = $this->projects($user, $filters);
        $projectIds = array_map(intval(...), $projects->modelKeys());
        $signals = TaskSignals::make($today);
        $schedules = [];

        foreach ($projects as $project) {
            $schedules[$project->id] = $project->schedule($today);
        }

        $isOpen = fn (Project $p): bool => ! $p->status->kind->lifecycle()->isClosed();
        $kind = fn (Project $p): ProjectStatusKind => $p->status->kind;
        $health = fn (Project $p): ScheduleHealth => $schedules[$p->id]->health;

        $overdue = $projects->filter(fn (Project $p) => $isOpen($p) && $health($p) === ScheduleHealth::Overdue);
        $behind = $projects->filter(fn (Project $p) => $isOpen($p) && $health($p) === ScheduleHealth::BehindSchedule);
        $dueSoon = $projects->filter(fn (Project $p) => $isOpen($p) && $health($p) === ScheduleHealth::DueSoon);
        // Same definition as the "at risk" filter of the project list: declared, or past due.
        $atRisk = $projects->filter(fn (Project $p) => $isOpen($p) && ($kind($p) === ProjectStatusKind::AtRisk || $health($p) === ScheduleHealth::Overdue));
        $stale = $this->stale($projects, $today);

        // Portfolio progress: open projects that already started and have a plan to compare against.
        $measured = $projects->filter(fn (Project $p) => $isOpen($p) && $schedules[$p->id]->expectedProgress !== null);

        return [
            'generated_at' => now()->toIso8601String(),
            'projects' => [
                'total' => $projects->count(),
                'active' => $projects->filter(fn (Project $p) => $kind($p)->lifecycle() === Lifecycle::Open)->count(),
                'planned' => $projects->filter(fn (Project $p) => $kind($p) === ProjectStatusKind::Planned)->count(),
                'paused' => $projects->filter(fn (Project $p) => $kind($p) === ProjectStatusKind::Paused)->count(),
                'completed' => $projects->filter(fn (Project $p) => $kind($p) === ProjectStatusKind::Completed)->count(),
                'cancelled' => $projects->filter(fn (Project $p) => $kind($p) === ProjectStatusKind::Cancelled)->count(),
                'at_risk' => $atRisk->count(),
                'overdue' => $overdue->count(),
                'behind' => $behind->count(),
                'due_soon' => $dueSoon->count(),
                'stale' => $stale->count(),
            ],
            'progress' => [
                'measured' => $measured->count(),
                'real' => $measured->isEmpty() ? null : (int) round($measured->avg('progress')),
                'expected' => $measured->isEmpty() ? null : (int) round($measured->avg(fn (Project $p): float => (float) $schedules[$p->id]->expectedProgress)),
            ],
            'by_status' => $this->byStatus($projects),
            'by_priority' => $this->byPriority($projects),
            'tasks' => $signals->summarize(DB::table('project_tasks')->whereIn('project_tasks.project_id', $projectIds)),
            'task_statuses' => $this->taskStatuses($projectIds),
            'workload' => $this->workload($projectIds, $today),
            'progress_gap' => $measured
                ->sortBy(fn (Project $p) => $schedules[$p->id]->progressGap)
                ->take(8)
                ->map(fn (Project $p) => $this->projectRow($p, $schedules[$p->id]))
                ->values()->all(),
            'upcoming' => $this->upcoming($projects, $projectIds, $schedules, $today),
            'alerts' => [
                'overdue_projects' => $this->listOf($overdue->sortBy('due_date'), $schedules),
                'behind_projects' => $this->listOf($behind->sortBy(fn (Project $p) => $schedules[$p->id]->progressGap), $schedules),
                'stale_projects' => $this->listOf($stale->sortBy('last_activity_at'), $schedules),
                'overdue_tasks' => $this->tasksWithSignal($projectIds, $signals, TaskSignals::OVERDUE),
                'due_soon_tasks' => $this->tasksWithSignal($projectIds, $signals, TaskSignals::DUE_SOON),
            ],
        ];
    }

    /**
     * @param  array{category?: int|string|null, owner?: int|string|null}  $filters
     * @return Collection<int, Project>
     */
    private function projects(User $user, array $filters): Collection
    {
        return Project::query()
            ->visibleTo($user)
            ->notArchived()
            ->when(filled($filters['category'] ?? null), fn (Builder $q) => $q->where('category_id', (int) $filters['category']))
            ->when(filled($filters['owner'] ?? null), fn (Builder $q) => $q->where('owner_id', (int) $filters['owner']))
            ->with(['status:id,name,kind,color,icon', 'owner:id,name'])
            ->get(['id', 'code', 'name', 'status_id', 'priority_id', 'owner_id', 'start_date', 'due_date', 'completed_at', 'progress', 'last_activity_at', 'created_at']);
    }

    /**
     * Open projects (active or at risk) without any logged activity for the
     * configured number of business days.
     *
     * @param  Collection<int, Project>  $projects
     * @return Collection<int, Project>
     */
    private function stale(Collection $projects, CarbonImmutable $today): Collection
    {
        $limit = $this->calendar->subBusinessDays($today, config()->integer('business_calendar.stale_after_business_days'));

        return $projects->filter(function (Project $p) use ($limit): bool {
            $last = $p->last_activity_at ?? $p->created_at;

            return $p->status->kind->lifecycle() === Lifecycle::Open
                && $last !== null
                && $last->startOfDay()->lt($limit);
        });
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @return array<int, array{id: int, name: string, color: string, icon: string|null, count: int}>
     */
    private function byStatus(Collection $projects): array
    {
        $counts = $projects->countBy('status_id');

        return ProjectStatus::query()->ordered()->get()
            ->filter(fn (ProjectStatus $s) => $s->is_active || $counts->has($s->id))
            ->map(fn (ProjectStatus $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'color' => $s->color,
                'icon' => $s->icon,
                'count' => (int) $counts->get($s->id, 0),
            ])->values()->all();
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @return array<int, array{id: int, name: string, color: string, icon: string|null, count: int}>
     */
    private function byPriority(Collection $projects): array
    {
        $counts = $projects->filter(fn (Project $p) => ! $p->status->kind->lifecycle()->isClosed())->countBy('priority_id');

        return ProjectPriority::query()->orderByDesc('level')->get()
            ->filter(fn (ProjectPriority $p) => $p->is_active || $counts->has($p->id))
            ->map(fn (ProjectPriority $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'color' => $p->color,
                'icon' => 'flag',
                'count' => (int) $counts->get($p->id, 0),
            ])->values()->all();
    }

    /**
     * @param  array<int, int>  $projectIds
     * @return array<int, array{id: int, name: string, color: string, icon: string|null, count: int}>
     */
    private function taskStatuses(array $projectIds): array
    {
        $counts = DB::table('project_tasks')
            ->whereIn('project_id', $projectIds)
            ->whereNull('deleted_at')
            ->groupBy('status_id')
            ->selectRaw('status_id, COUNT(*) AS total')
            ->pluck('total', 'status_id');

        return DB::table('project_task_statuses')->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'color', 'icon', 'is_active'])
            ->filter(fn ($s) => $s->is_active || $counts->has($s->id))
            ->map(fn ($s) => [
                'id' => (int) $s->id,
                'name' => (string) $s->name,
                'color' => (string) $s->color,
                'icon' => $s->icon === null ? null : (string) $s->icon,
                'count' => (int) $counts->get($s->id, 0),
            ])->values()->all();
    }

    /**
     * Open tasks per person (split into on time / overdue) plus the open
     * projects they own. Top 10 by open tasks.
     *
     * @param  array<int, int>  $projectIds
     * @return array<int, array{user_id: int, name: string, open: int, overdue: int, owned_projects: int}>
     */
    private function workload(array $projectIds, CarbonImmutable $today): array
    {
        $open = array_map(fn ($kind) => $kind->value, TaskSignals::OPEN_KINDS);

        $rows = DB::table('project_tasks')
            ->join('project_task_statuses', 'project_task_statuses.id', '=', 'project_tasks.status_id')
            ->whereIn('project_tasks.project_id', $projectIds)
            ->whereNull('project_tasks.deleted_at')
            ->whereNotNull('project_tasks.assignee_id')
            ->whereIn('project_task_statuses.kind', $open)
            ->groupBy('project_tasks.assignee_id')
            ->selectRaw('project_tasks.assignee_id, COUNT(*) AS open, COUNT(*) FILTER (WHERE project_tasks.due_date < ?) AS overdue', [$today->toDateString()])
            ->orderByDesc('open')
            ->limit(10)
            ->get();

        $userIds = $rows->pluck('assignee_id')->map(fn ($id) => (int) $id)->all();
        $names = User::query()->whereKey($userIds)->pluck('name', 'id');
        $owned = Project::query()->whereKey($projectIds)->whereIn('owner_id', $userIds)
            ->whereHas('status', fn (Builder $q) => $q->whereIn('kind', [ProjectStatusKind::Planned->value, ProjectStatusKind::Active->value, ProjectStatusKind::Paused->value, ProjectStatusKind::AtRisk->value]))
            ->groupBy('owner_id')->selectRaw('owner_id, COUNT(*) AS total')->pluck('total', 'owner_id');

        return $rows->map(fn ($row) => [
            'user_id' => (int) $row->assignee_id,
            'name' => (string) ($names[$row->assignee_id] ?? '—'),
            'open' => (int) $row->open,
            'overdue' => (int) $row->overdue,
            'owned_projects' => (int) $owned->get($row->assignee_id, 0),
        ])->values()->all();
    }

    /**
     * Projects and tasks due within the next 30 calendar days, by date.
     *
     * @param  Collection<int, Project>  $projects
     * @param  array<int, int>  $projectIds
     * @param  array<int, ScheduleSnapshot>  $schedules
     * @return array<int, array<string, mixed>>
     */
    private function upcoming(Collection $projects, array $projectIds, array $schedules, CarbonImmutable $today): array
    {
        $until = $today->addDays(self::UPCOMING_CALENDAR_DAYS);

        $items = $projects
            ->filter(fn (Project $p) => ! $p->status->kind->lifecycle()->isClosed()
                && $p->due_date !== null && $p->due_date->betweenIncluded($today, $until))
            ->map(fn (Project $p) => [
                'type' => 'project',
                'id' => $p->id,
                'project_id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'project' => null,
                'owner' => $p->owner->name,
                'due_date' => $p->due_date?->toDateString(),
                'remaining' => $schedules[$p->id]->remainingDays,
            ])->values();

        $tasks = ProjectTask::query()
            ->whereIn('project_id', $projectIds)
            ->whereHas('status', fn (Builder $q) => $q->whereIn('kind', array_map(fn ($k) => $k->value, TaskSignals::OPEN_KINDS)))
            ->whereBetween('due_date', [$today->toDateString(), $until->toDateString()])
            ->with(['project:id,code,name', 'assignee:id,name'])
            ->orderBy('due_date')
            ->limit(20)
            ->get(['id', 'project_id', 'name', 'assignee_id', 'due_date'])
            ->map(fn (ProjectTask $t) => [
                'type' => 'task',
                'id' => $t->id,
                'project_id' => $t->project_id,
                'code' => $t->project->code,
                'name' => $t->name,
                'project' => $t->project->name,
                'owner' => $t->assignee?->name,
                'due_date' => $t->due_date?->toDateString(),
                'remaining' => $t->due_date ? $this->calendar->countBetween($today, $t->due_date) : null,
            ]);

        return $items->concat($tasks)
            ->sortBy([['due_date', 'asc'], ['type', 'asc']])
            ->take(12)
            ->values()->all();
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @param  array<int, ScheduleSnapshot>  $schedules
     * @return array{count: int, items: array<int, array<string, mixed>>}
     */
    private function listOf(Collection $projects, array $schedules): array
    {
        return [
            'count' => $projects->count(),
            'items' => $projects->take(self::LIST_LIMIT)
                ->map(fn (Project $p) => $this->projectRow($p, $schedules[$p->id]))
                ->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function projectRow(Project $project, ScheduleSnapshot $schedule): array
    {
        return [
            'id' => $project->id,
            'code' => $project->code,
            'name' => $project->name,
            'owner' => $project->owner->name,
            'progress' => $project->progress,
            'expected' => $schedule->expectedProgress,
            'gap' => $schedule->progressGap,
            'overdue_days' => $schedule->overdueDays,
            'remaining' => $schedule->remainingDays,
            'health' => $schedule->health->value,
            'last_activity' => ($project->last_activity_at ?? $project->created_at)?->toIso8601String(),
        ];
    }

    /**
     * @param  array<int, int>  $projectIds
     * @return array{count: int, items: array<int, array<string, mixed>>}
     */
    private function tasksWithSignal(array $projectIds, TaskSignals $signals, string $signal): array
    {
        $query = ProjectTask::query()->whereIn('project_tasks.project_id', $projectIds);
        $signals->apply($query, $signal);

        return [
            'count' => (clone $query)->count(),
            'items' => $query->with(['project:id,code,name', 'assignee:id,name'])
                ->orderBy('due_date')
                ->limit(self::LIST_LIMIT)
                ->get(['id', 'project_id', 'name', 'assignee_id', 'due_date'])
                ->map(fn (ProjectTask $t) => [
                    'id' => $t->id,
                    'project_id' => $t->project_id,
                    'code' => $t->project->code,
                    'name' => $t->name,
                    'owner' => $t->assignee?->name,
                    'due_date' => $t->due_date?->toDateString(),
                ])->values()->all(),
        ];
    }
}
