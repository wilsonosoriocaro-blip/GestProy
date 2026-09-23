<?php

namespace App\Services\Projects;

use App\Models\ProjectTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Validates predecessors of a task: same project, not itself and no cycles
 * (A waits on B, B waits on A). Loads the project's dependency edges in one
 * query and walks them in memory.
 */
class TaskDependencyGuard
{
    /**
     * @param  list<int>  $dependsOn
     *
     * @throws ValidationException
     */
    public function validate(int $projectId, ?int $taskId, array $dependsOn, string $field = 'dependencies'): void
    {
        $dependsOn = array_values(array_unique($dependsOn));

        if ($dependsOn === []) {
            return;
        }

        if ($taskId !== null && in_array($taskId, $dependsOn, true)) {
            throw ValidationException::withMessages([$field => 'Una tarea no puede depender de sí misma.']);
        }

        $sameProject = ProjectTask::query()->where('project_id', $projectId)->whereKey($dependsOn)->count();

        if ($sameProject !== count($dependsOn)) {
            throw ValidationException::withMessages([$field => 'Las dependencias deben ser tareas del mismo proyecto.']);
        }

        if ($taskId !== null && $this->createsCycle($projectId, $taskId, $dependsOn)) {
            throw ValidationException::withMessages([$field => 'Esa dependencia crea un ciclo: una de las tareas elegidas ya espera por esta.']);
        }
    }

    /**
     * True when $taskId is reachable from any of the new predecessors.
     *
     * @param  list<int>  $dependsOn
     */
    private function createsCycle(int $projectId, int $taskId, array $dependsOn): bool
    {
        $edges = DB::table('project_task_dependencies')
            ->join('project_tasks', 'project_tasks.id', '=', 'project_task_dependencies.task_id')
            ->where('project_tasks.project_id', $projectId)
            ->where('project_task_dependencies.task_id', '!=', $taskId)
            ->get(['project_task_dependencies.task_id', 'project_task_dependencies.depends_on_id'])
            ->groupBy('task_id')
            ->map(fn ($rows) => $rows->pluck('depends_on_id')->map(fn ($id) => (int) $id)->all());

        $pending = $dependsOn;
        $visited = [];

        while ($pending !== []) {
            $current = array_pop($pending);

            if ($current === $taskId) {
                return true;
            }

            if (isset($visited[$current])) {
                continue;
            }

            $visited[$current] = true;

            foreach ($edges->get($current, []) as $next) {
                $pending[] = $next;
            }
        }

        return false;
    }
}
