<?php

namespace Database\Seeders;

use App\Enums\ProgressMode;
use App\Enums\ProjectMemberRole;
use App\Enums\Role;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectPriority;
use App\Models\ProjectStatus;
use App\Models\User;
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
    }
}
