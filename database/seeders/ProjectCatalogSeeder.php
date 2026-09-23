<?php

namespace Database\Seeders;

use App\Enums\ProjectStatusKind;
use App\Enums\TaskStatusKind;
use App\Models\ProjectCategory;
use App\Models\ProjectPriority;
use App\Models\ProjectStatus;
use App\Models\ProjectTaskStatus;
use Illuminate\Database\Seeder;

/**
 * Initial catalogs of the projects module. Idempotent: rows are matched by
 * slug, so running it again never duplicates data nor overrides the names
 * or colors an administrator changed afterwards.
 */
class ProjectCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['infraestructura', 'Infraestructura', 'slate'],
            ['sap', 'SAP', 'blue'],
            ['desarrollo', 'Desarrollo', 'indigo'],
            ['integraciones', 'Integraciones', 'cyan'],
            ['inteligencia-artificial', 'Inteligencia Artificial', 'violet'],
            ['automatizacion', 'Automatización', 'teal'],
            ['datos-bi', 'Datos / BI', 'emerald'],
            ['ciberseguridad', 'Ciberseguridad', 'red'],
            ['transformacion-digital', 'Transformación Digital', 'fuchsia'],
            ['soporte-continuidad', 'Soporte / continuidad', 'amber'],
            ['otros', 'Otros', 'zinc'],
        ];

        foreach ($categories as $order => [$slug, $name, $color]) {
            ProjectCategory::firstOrCreate(['slug' => $slug], [
                'name' => $name,
                'color' => $color,
                'sort_order' => $order + 1,
            ]);
        }

        $projectStatuses = [
            ['planeado', ProjectStatusKind::Planned, 'zinc', 'calendar', true],
            ['en-ejecucion', ProjectStatusKind::Active, 'blue', 'play-circle', false],
            ['en-pausa', ProjectStatusKind::Paused, 'sky', 'pause-circle', false],
            ['en-riesgo', ProjectStatusKind::AtRisk, 'orange', 'exclamation-triangle', false],
            ['finalizado', ProjectStatusKind::Completed, 'green', 'check-circle', false],
            ['cancelado', ProjectStatusKind::Cancelled, 'zinc', 'x-circle', false],
        ];

        foreach ($projectStatuses as $order => [$slug, $kind, $color, $icon, $default]) {
            ProjectStatus::firstOrCreate(['slug' => $slug], [
                'name' => $kind->label(),
                'kind' => $kind,
                'color' => $color,
                'icon' => $icon,
                'is_default' => $default,
                'sort_order' => $order + 1,
            ]);
        }

        $taskStatuses = [
            ['pendiente', TaskStatusKind::Pending, 'zinc', 'clock', true],
            ['en-ejecucion', TaskStatusKind::InProgress, 'blue', 'play-circle', false],
            ['bloqueada', TaskStatusKind::Blocked, 'red', 'no-symbol', false],
            ['en-revision', TaskStatusKind::InReview, 'violet', 'eye', false],
            ['finalizada', TaskStatusKind::Completed, 'green', 'check-circle', false],
            ['cancelada', TaskStatusKind::Cancelled, 'zinc', 'x-circle', false],
        ];

        foreach ($taskStatuses as $order => [$slug, $kind, $color, $icon, $default]) {
            ProjectTaskStatus::firstOrCreate(['slug' => $slug], [
                'name' => $kind->label(),
                'kind' => $kind,
                'color' => $color,
                'icon' => $icon,
                'is_default' => $default,
                'sort_order' => $order + 1,
            ]);
        }

        $priorities = [
            ['baja', 'Baja', 10, 'zinc', false],
            ['media', 'Media', 20, 'sky', true],
            ['alta', 'Alta', 30, 'amber', false],
            ['critica', 'Crítica', 40, 'red', false],
        ];

        foreach ($priorities as [$slug, $name, $level, $color, $default]) {
            ProjectPriority::firstOrCreate(['slug' => $slug], [
                'name' => $name,
                'level' => $level,
                'color' => $color,
                'is_default' => $default,
            ]);
        }
    }
}
