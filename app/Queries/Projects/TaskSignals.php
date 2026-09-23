<?php

namespace App\Queries\Projects;

use App\Enums\TaskStatusKind;
use App\Models\ProjectTask;
use App\Models\ProjectTaskStatus;
use App\Support\Calendar\BusinessCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * SQL definitions of the task alerts, shared by the task list filters, the
 * summary counters and (later) the dashboard, so every screen agrees on
 * what "overdue" or "due soon" means. They match ScheduleCalculator:
 *
 *  - overdue: open task whose due date already passed
 *  - due soon: open task due within the next N business days (today included)
 *  - blocked: status of kind "blocked"
 *  - without progress: open task at 0% whose start date already arrived
 */
class TaskSignals
{
    public const OVERDUE = 'overdue';

    public const DUE_SOON = 'due_soon';

    public const BLOCKED = 'blocked';

    public const WITHOUT_PROGRESS = 'without_progress';

    public const LABELS = [
        self::OVERDUE => 'Vencidas',
        self::DUE_SOON => 'Próximas a vencer',
        self::BLOCKED => 'Bloqueadas',
        self::WITHOUT_PROGRESS => 'Sin avance',
    ];

    /** Kinds of a task that is still expected to be worked on. */
    public const OPEN_KINDS = [
        TaskStatusKind::Pending,
        TaskStatusKind::InProgress,
        TaskStatusKind::Blocked,
        TaskStatusKind::InReview,
    ];

    private CarbonImmutable $today;

    private CarbonImmutable $dueSoonLimit;

    public function __construct(BusinessCalendar $calendar, int $dueSoonDays, ?CarbonImmutable $today = null)
    {
        $this->today = ($today ?? CarbonImmutable::today())->startOfDay();
        $this->dueSoonLimit = $calendar->addBusinessDays($this->today, max(0, $dueSoonDays - 1));
    }

    public static function make(?CarbonImmutable $today = null): self
    {
        return new self(app(BusinessCalendar::class), config()->integer('business_calendar.due_soon_business_days'), $today);
    }

    /**
     * Restricts a task query to one signal. The query must be on project_tasks.
     *
     * @param  Builder<ProjectTask>  $query
     */
    public function apply(Builder $query, string $signal): void
    {
        match ($signal) {
            self::OVERDUE => $query->whereHas('status', fn (Builder $q) => $this->whereOpenKind($q))
                ->whereDate('project_tasks.due_date', '<', $this->today),
            self::DUE_SOON => $query->whereHas('status', fn (Builder $q) => $this->whereOpenKind($q))
                ->whereBetween('project_tasks.due_date', [$this->today->toDateString(), $this->dueSoonLimit->toDateString()]),
            self::BLOCKED => $query->whereHas('status', fn (Builder $q) => $q->where('kind', TaskStatusKind::Blocked->value)),
            self::WITHOUT_PROGRESS => $query->whereHas('status', fn (Builder $q) => $this->whereOpenKind($q))
                ->where('project_tasks.progress', 0)
                ->whereDate('project_tasks.start_date', '<=', $this->today),
            default => null,
        };
    }

    /**
     * Counters per status kind and per signal, in a single aggregate query.
     *
     * @param  QueryBuilder  $tasks  Base query on project_tasks (already scoped).
     * @return array{total: int, by_kind: array<string, int>, signals: array<string, int>}
     */
    public function summarize(QueryBuilder $tasks): array
    {
        $open = implode(',', array_map(fn (TaskStatusKind $kind) => "'{$kind->value}'", self::OPEN_KINDS));

        $row = $tasks
            ->join('project_task_statuses as sig_status', 'sig_status.id', '=', 'project_tasks.status_id')
            ->whereNull('project_tasks.deleted_at')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("COUNT(*) FILTER (WHERE sig_status.kind = 'pending') AS pending")
            ->selectRaw("COUNT(*) FILTER (WHERE sig_status.kind = 'in_progress') AS in_progress")
            ->selectRaw("COUNT(*) FILTER (WHERE sig_status.kind = 'blocked') AS blocked")
            ->selectRaw("COUNT(*) FILTER (WHERE sig_status.kind = 'in_review') AS in_review")
            ->selectRaw("COUNT(*) FILTER (WHERE sig_status.kind = 'completed') AS completed")
            ->selectRaw("COUNT(*) FILTER (WHERE sig_status.kind = 'cancelled') AS cancelled")
            ->selectRaw("COUNT(*) FILTER (WHERE sig_status.kind IN ({$open}) AND project_tasks.due_date < ?) AS overdue", [$this->today->toDateString()])
            ->selectRaw("COUNT(*) FILTER (WHERE sig_status.kind IN ({$open}) AND project_tasks.due_date BETWEEN ? AND ?) AS due_soon", [$this->today->toDateString(), $this->dueSoonLimit->toDateString()])
            ->selectRaw("COUNT(*) FILTER (WHERE sig_status.kind IN ({$open}) AND project_tasks.progress = 0 AND project_tasks.start_date <= ?) AS without_progress", [$this->today->toDateString()])
            ->first();

        $value = fn (string $key): int => (int) ($row->{$key} ?? 0);

        return [
            'total' => $value('total'),
            'by_kind' => array_combine(
                array_map(fn (TaskStatusKind $kind) => $kind->value, TaskStatusKind::cases()),
                array_map(fn (TaskStatusKind $kind) => $value($kind->value), TaskStatusKind::cases()),
            ),
            'signals' => [
                self::OVERDUE => $value('overdue'),
                self::DUE_SOON => $value('due_soon'),
                self::BLOCKED => $value('blocked'),
                self::WITHOUT_PROGRESS => $value('without_progress'),
            ],
        ];
    }

    /**
     * @param  Builder<ProjectTaskStatus>  $query
     */
    private function whereOpenKind(Builder $query): void
    {
        $query->whereIn('kind', array_map(fn (TaskStatusKind $kind) => $kind->value, self::OPEN_KINDS));
    }

    /**
     * Convenience: base query on the tasks of one project.
     */
    public static function tasksOf(int $projectId): QueryBuilder
    {
        return DB::table('project_tasks')->where('project_tasks.project_id', $projectId);
    }
}
