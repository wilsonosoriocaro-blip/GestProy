<?php

namespace Database\Seeders;

use App\Actions\Comments\ManageProjectComments;
use App\Enums\ProgressMode;
use App\Enums\ProjectMemberRole;
use App\Enums\Role;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectPriority;
use App\Models\ProjectStatus;
use App\Models\ProjectTask;
use App\Models\ProjectTaskStatus;
use App\Models\User;
use App\Services\Projects\ProjectProgressCalculator;
use Illuminate\Database\Seeder;

/**
 * Sample portfolio for local environments. Not called by DatabaseSeeder:
 * run it explicitly with `php artisan db:seed --class=DemoProjectsSeeder`.
 */
class DemoProjectsSeeder extends Seeder
{
    public function run(): void
    {
        $leader = User::firstOrCreate(['email' => 'lider@example.com'], ['name' => 'Laura Gómez', 'password' => 'password', 'email_verified_at' => now()]);
        $leader->assignRole(Role::Leader->value);

        $managers = collect([
            ['andres@example.com', 'Andrés Ríos'],
            ['camila@example.com', 'Camila Torres'],
            ['julian@example.com', 'Julián Pérez'],
        ])->map(function (array $person): User {
            $user = User::firstOrCreate(['email' => $person[0]], ['name' => $person[1], 'password' => 'password', 'email_verified_at' => now()]);

            return $user->assignRole(Role::ProjectManager->value);
        });

        $status = fn (string $slug): int => ProjectStatus::where('slug', $slug)->value('id');
        $priority = fn (string $slug): int => ProjectPriority::where('slug', $slug)->value('id');
        $category = fn (string $slug): int => ProjectCategory::where('slug', $slug)->value('id');

        $projects = [
            ['Migración a SAP S/4HANA', 'sap', 'en-ejecucion', 'critica', -120, 90, 45],
            ['Integración SAP con portal de proveedores', 'integraciones', 'en-riesgo', 'alta', -60, 10, 40],
            ['Renovación de firewalls perimetrales', 'infraestructura', 'en-ejecucion', 'alta', -45, -5, 80],
            ['Asistente de IA para mesa de ayuda', 'inteligencia-artificial', 'planeado', 'media', 15, 120, 0],
            ['Automatización de conciliaciones bancarias', 'automatizacion', 'en-ejecucion', 'media', -30, 30, 20],
            ['Tablero de indicadores comerciales', 'datos-bi', 'finalizado', 'media', -90, -20, 100],
            ['Programa de concientización en ciberseguridad', 'ciberseguridad', 'en-pausa', 'baja', -40, 60, 30],
            ['Digitalización de trámites internos', 'transformacion-digital', 'en-ejecucion', 'alta', -10, 50, 15],
            ['Plan de continuidad del ERP', 'soporte-continuidad', 'cancelado', 'baja', -100, -30, 10],
            ['App móvil de fuerza de ventas', 'desarrollo', 'en-ejecucion', 'alta', -70, 4, 85],
        ];

        foreach ($projects as $index => [$name, $cat, $st, $prio, $startOffset, $dueOffset, $progress]) {
            $owner = $managers[$index % $managers->count()];

            $project = Project::firstOrNew(['code' => sprintf('DEMO-%03d', $index + 1)]);
            $project->forceFill([
                'name' => $name,
                'description' => "Proyecto de ejemplo: {$name}.",
                'objective' => 'Mejorar la eficiencia operativa del área.',
                'category_id' => $category($cat),
                'status_id' => $status($st),
                'priority_id' => $priority($prio),
                'owner_id' => $owner->id,
                'start_date' => today()->addDays($startOffset),
                'due_date' => today()->addDays($dueOffset),
                'completed_at' => $st === 'finalizado' ? today()->addDays($dueOffset - 3) : null,
                'progress' => $progress,
                'progress_mode' => ProgressMode::Manual,
                'created_by' => $leader->id,
            ])->save();

            $teammate = $managers[($index + 1) % $managers->count()];
            $project->members()->syncWithoutDetaching([$teammate->id => ['role' => ProjectMemberRole::Member->value]]);
        }

        $sap = Project::where('code', 'DEMO-001')->firstOrFail();
        $this->seedTasks($sap);
        $this->seedLog($sap, $leader);
    }

    /**
     * A few log entries, written through the real action so they also land in the history.
     */
    private function seedLog(Project $project, User $leader): void
    {
        if ($project->comments()->exists()) {
            return;
        }

        $comments = app(ManageProjectComments::class);
        $owner = $project->owner;

        $comments->add($owner, $project, 'Se aprobó el blueprint con el comité de Finanzas. Arranca la configuración de FI/CO.', true);
        $comments->add($leader, $project, 'Riesgo: la migración de datos maestros depende de la depuración que hace Finanzas. Se escaló al director.');
        $comments->add($owner, $project, 'Se finalizó la integración con SAP del portal de proveedores y se inició la fase de pruebas con usuarios.');
    }

    /**
     * A task plan for the SAP migration, with progress calculated from it.
     */
    private function seedTasks(Project $project): void
    {
        if ($project->tasks()->exists()) {
            $this->useTaskProgress($project);

            return;
        }

        $status = fn (string $slug): int => ProjectTaskStatus::where('slug', $slug)->value('id');
        $priority = fn (string $slug): int => ProjectPriority::where('slug', $slug)->value('id');
        $team = $project->memberships()->pluck('user_id')->push($project->owner_id)->values();

        $plan = [
            ['Levantamiento de procesos actuales', 'finalizada', -110, -80, 100, 2],
            ['Diseño de la solución (blueprint)', 'finalizada', -80, -45, 100, 3],
            ['Configuración de módulos FI/CO', 'en-ejecucion', -45, 5, 70, 3],
            ['Migración de datos maestros', 'bloqueada', -30, 10, 35, 2],
            ['Integración con portal de proveedores', 'en-ejecucion', -20, -2, 40, 2],
            ['Pruebas integrales', 'pendiente', -5, 40, 0, 3],
            ['Capacitación a usuarios clave', 'pendiente', 30, 60, 0, 1],
            ['Salida en vivo', 'pendiente', 70, 90, 0, 1],
        ];

        $previous = null;

        foreach ($plan as $order => [$name, $st, $start, $due, $progress, $weight]) {
            $task = new ProjectTask;
            $task->forceFill([
                'project_id' => $project->id,
                'name' => $name,
                'assignee_id' => $team[$order % $team->count()],
                'status_id' => $status($st),
                'priority_id' => $priority($order < 5 ? 'alta' : 'media'),
                'start_date' => today()->addDays($start),
                'due_date' => today()->addDays($due),
                'completed_at' => $st === 'finalizada' ? today()->addDays($due) : null,
                'progress' => $progress,
                'weight' => $weight,
                'sort_order' => $order + 1,
                'notes' => $st === 'bloqueada' ? 'Esperando validación de datos por parte de Finanzas.' : null,
            ])->save();

            if ($previous !== null && $order >= 5) {
                $task->dependencies()->attach($previous);
            }

            $previous = $task;
        }

        $this->useTaskProgress($project);
    }

    private function useTaskProgress(Project $project): void
    {
        $project->forceFill(['progress_mode' => ProgressMode::Tasks])->save();
        app(ProjectProgressCalculator::class)->sync($project);
    }
}
