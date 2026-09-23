<?php

namespace App\Services\Projects;

use App\Enums\ProgressMode;
use App\Enums\ProjectMemberRole;
use App\Models\ProjectActivityLog;
use App\Models\ProjectCategory;
use App\Models\ProjectPriority;
use App\Models\ProjectStatus;
use App\Models\ProjectTask;
use App\Models\ProjectTaskStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

/**
 * Turns the raw old/new values stored in project_activity_logs into rows a
 * person can read: Spanish field names, names instead of ids, formatted
 * dates, percentages and money. Ids are resolved in one query per catalog
 * for the whole page of entries.
 */
class ActivityChangeFormatter
{
    private const LABELS = [
        'code' => 'Código',
        'name' => 'Nombre',
        'description' => 'Descripción',
        'objective' => 'Objetivo',
        'scope' => 'Alcance',
        'notes' => 'Observaciones',
        'body' => 'Texto',
        'category_id' => 'Categoría',
        'status_id' => 'Estado',
        'priority_id' => 'Prioridad',
        'owner_id' => 'Responsable',
        'assignee_id' => 'Responsable',
        'start_date' => 'Inicio',
        'due_date' => 'Vencimiento',
        'completed_at' => 'Finalización real',
        'progress' => 'Avance',
        'progress_mode' => 'Modo de avance',
        'budget' => 'Presupuesto',
        'weight' => 'Peso',
        'role' => 'Rol',
        'user_id' => 'Persona',
        'dependencies' => 'Dependencias',
        'highlighted' => 'Destacado',
    ];

    /** Keys that only duplicate a readable value stored next to them, or are internal. */
    private const HIDDEN = ['status', 'owner', 'assignee', 'comment_id', 'author_id'];

    /**
     * @param  Collection<int, ProjectActivityLog>  $logs
     * @return array<int, list<array{field: string, old: string, new: string}>> Rows per log id.
     */
    public function format(Collection $logs): array
    {
        $lookups = $this->lookups($logs);
        $result = [];

        foreach ($logs as $log) {
            $old = $log->old_values ?? [];
            $new = $log->new_values ?? [];
            $rows = [];

            foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $field) {
                if (in_array($field, self::HIDDEN, true)) {
                    continue;
                }

                $rows[] = [
                    'field' => self::LABELS[$field] ?? $field,
                    'old' => $this->value($field, $old[$field] ?? null, $log, $lookups, 'old'),
                    'new' => $this->value($field, $new[$field] ?? null, $log, $lookups, 'new'),
                ];
            }

            $result[$log->id] = $rows;
        }

        return $result;
    }

    /**
     * @param  array<string, array<int, string>>  $lookups
     */
    private function value(string $field, mixed $value, ProjectActivityLog $log, array $lookups, string $side): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '—';
        }

        $values = $side === 'old' ? ($log->old_values ?? []) : ($log->new_values ?? []);

        return match ($field) {
            // The readable name was stored at the time of the change: prefer it.
            'status_id' => (string) ($values['status'] ?? $this->statusName($log, (int) $value, $lookups)),
            'owner_id' => (string) ($values['owner'] ?? $lookups['users'][(int) $value] ?? "#{$value}"),
            'assignee_id' => (string) ($values['assignee'] ?? $lookups['users'][(int) $value] ?? "#{$value}"),
            'user_id' => $lookups['users'][(int) $value] ?? "#{$value}",
            'category_id' => $lookups['categories'][(int) $value] ?? "#{$value}",
            'priority_id' => $lookups['priorities'][(int) $value] ?? "#{$value}",
            'start_date', 'due_date', 'completed_at' => CarbonImmutable::parse((string) $value)->translatedFormat('d M Y'),
            'progress' => $value.'%',
            'progress_mode' => ProgressMode::tryFrom((string) $value)?->label() ?? (string) $value,
            'budget' => Number::currency((float) $value, in: 'COP', locale: 'es_CO') ?: '$ '.number_format((float) $value, 2, ',', '.'),
            'role' => ProjectMemberRole::tryFrom((string) $value)?->label() ?? (string) $value,
            'highlighted' => $value ? 'Sí' : 'No',
            'dependencies' => collect((array) $value)->map(fn ($id) => $lookups['tasks'][(int) $id] ?? "#{$id}")->join(', '),
            default => is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE),
        };
    }

    /**
     * @param  array<string, array<int, string>>  $lookups
     */
    private function statusName(ProjectActivityLog $log, int $id, array $lookups): string
    {
        $catalog = $log->task_id !== null ? 'task_statuses' : 'statuses';

        return $lookups[$catalog][$id] ?? "#{$id}";
    }

    /**
     * @param  Collection<int, ProjectActivityLog>  $logs
     * @return array<string, array<int, string>>
     */
    private function lookups(Collection $logs): array
    {
        $ids = ['users' => [], 'categories' => [], 'priorities' => [], 'statuses' => [], 'task_statuses' => [], 'tasks' => []];

        foreach ($logs as $log) {
            foreach ([$log->old_values ?? [], $log->new_values ?? []] as $values) {
                foreach ($values as $field => $value) {
                    if ($value === null) {
                        continue;
                    }

                    match ($field) {
                        'owner_id', 'assignee_id', 'user_id' => $ids['users'][] = (int) $value,
                        'category_id' => $ids['categories'][] = (int) $value,
                        'priority_id' => $ids['priorities'][] = (int) $value,
                        'status_id' => $ids[$log->task_id !== null ? 'task_statuses' : 'statuses'][] = (int) $value,
                        'dependencies' => array_push($ids['tasks'], ...array_map('intval', (array) $value)),
                        default => null,
                    };
                }
            }
        }

        $names = fn (string $model, array $keys): array => $keys === [] ? [] : $model::query()->withoutGlobalScopes()->whereKey(array_unique($keys))->pluck('name', 'id')->all();

        return [
            'users' => $names(User::class, $ids['users']),
            'categories' => $names(ProjectCategory::class, $ids['categories']),
            'priorities' => $names(ProjectPriority::class, $ids['priorities']),
            'statuses' => $names(ProjectStatus::class, $ids['statuses']),
            'task_statuses' => $names(ProjectTaskStatus::class, $ids['task_statuses']),
            'tasks' => $names(ProjectTask::class, $ids['tasks']),
        ];
    }
}
