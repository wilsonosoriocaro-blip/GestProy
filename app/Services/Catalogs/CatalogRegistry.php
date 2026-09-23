<?php

namespace App\Services\Catalogs;

use App\Enums\ProjectStatusKind;
use App\Enums\TaskStatusKind;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectPriority;
use App\Models\ProjectStatus;
use App\Models\ProjectTask;
use App\Models\ProjectTaskStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Describes each administrable catalog: model, which optional fields it
 * has and how to count the records that use an entry.
 */
class CatalogRegistry
{
    /** Colors Flux badges support. */
    public const COLORS = ['zinc', 'slate', 'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald', 'teal', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose'];

    /** Heroicons offered for statuses. */
    public const ICONS = ['calendar', 'clock', 'play-circle', 'pause-circle', 'check-circle', 'x-circle', 'exclamation-triangle', 'no-symbol', 'eye', 'flag', 'bolt', 'sparkles', 'shield-exclamation', 'arrow-path'];

    /**
     * @return array<string, array{label: string, model: class-string<Model>, kinds: class-string<\BackedEnum>|null, icon: bool, level: bool, order: bool, default: bool}>
     */
    public static function all(): array
    {
        return [
            'categories' => ['label' => 'Categorías', 'model' => ProjectCategory::class, 'kinds' => null, 'icon' => false, 'level' => false, 'order' => true, 'default' => false],
            'project_statuses' => ['label' => 'Estados de proyecto', 'model' => ProjectStatus::class, 'kinds' => ProjectStatusKind::class, 'icon' => true, 'level' => false, 'order' => true, 'default' => true],
            'task_statuses' => ['label' => 'Estados de tarea', 'model' => ProjectTaskStatus::class, 'kinds' => TaskStatusKind::class, 'icon' => true, 'level' => false, 'order' => true, 'default' => true],
            'priorities' => ['label' => 'Prioridades', 'model' => ProjectPriority::class, 'kinds' => null, 'icon' => false, 'level' => true, 'order' => false, 'default' => true],
        ];
    }

    /**
     * @return array{label: string, model: class-string<Model>, kinds: class-string<\BackedEnum>|null, icon: bool, level: bool, order: bool, default: bool}
     */
    public static function get(string $type): array
    {
        return self::all()[$type] ?? throw new \InvalidArgumentException("Unknown catalog [{$type}].");
    }

    /**
     * Usage of every entry of a catalog in grouped queries (no query per row).
     *
     * @return array<int, int> Count per entry id.
     */
    public static function usageCounts(string $type): array
    {
        $count = function (string $model, string $column): array {
            return $model::withTrashed()
                ->selectRaw("{$column} AS entry_id, COUNT(*) AS total")
                ->groupBy($column)
                ->pluck('total', 'entry_id')
                ->map(fn ($total) => (int) $total)
                ->all();
        };

        $counts = match ($type) {
            'categories' => [$count(Project::class, 'category_id')],
            'project_statuses' => [$count(Project::class, 'status_id')],
            'task_statuses' => [$count(ProjectTask::class, 'status_id')],
            'priorities' => [$count(Project::class, 'priority_id'), $count(ProjectTask::class, 'priority_id')],
            default => [],
        };

        $total = [];

        foreach ($counts as $group) {
            foreach ($group as $id => $n) {
                $total[(int) $id] = ($total[(int) $id] ?? 0) + $n;
            }
        }

        return $total;
    }

    /**
     * Projects and tasks (deleted ones included) that point to the entry.
     */
    public static function usage(string $type, int $id): int
    {
        return match ($type) {
            'categories' => Project::withTrashed()->where('category_id', $id)->count(),
            'project_statuses' => Project::withTrashed()->where('status_id', $id)->count(),
            'task_statuses' => ProjectTask::withTrashed()->where('status_id', $id)->count(),
            'priorities' => Project::withTrashed()->where('priority_id', $id)->count() + ProjectTask::withTrashed()->where('priority_id', $id)->count(),
            default => 0,
        };
    }
}
