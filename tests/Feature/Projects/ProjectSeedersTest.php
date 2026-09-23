<?php

namespace Tests\Feature\Projects;

use App\Enums\Permission;
use App\Enums\ProjectStatusKind;
use App\Enums\Role;
use App\Enums\TaskStatusKind;
use App\Models\ProjectCategory;
use App\Models\ProjectPriority;
use App\Models\ProjectStatus;
use App\Models\ProjectTaskStatus;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ProjectCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectSeedersTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalogs_are_seeded_once(): void
    {
        $this->seed(ProjectCatalogSeeder::class);
        $this->seed(ProjectCatalogSeeder::class);

        $this->assertSame(11, ProjectCategory::count());
        $this->assertSame(6, ProjectStatus::count());
        $this->assertSame(6, ProjectTaskStatus::count());
        $this->assertSame(4, ProjectPriority::count());
        $this->assertSame(1, ProjectStatus::where('is_default', true)->count());
    }

    public function test_statuses_can_be_filtered_by_kind(): void
    {
        $this->seed(ProjectCatalogSeeder::class);

        $this->assertSame(['en-riesgo'], ProjectStatus::ofKind(ProjectStatusKind::AtRisk)->pluck('slug')->all());
        $this->assertSame(2, ProjectStatus::ofKind([ProjectStatusKind::Completed, ProjectStatusKind::Cancelled])->count());
        $this->assertSame(['bloqueada'], ProjectTaskStatus::ofKind(TaskStatusKind::Blocked)->pluck('slug')->all());
    }

    public function test_seeding_again_keeps_administrator_changes(): void
    {
        $this->seed(ProjectCatalogSeeder::class);
        ProjectCategory::where('slug', 'sap')->update(['name' => 'SAP S/4HANA']);

        $this->seed(ProjectCatalogSeeder::class);

        $this->assertSame('SAP S/4HANA', ProjectCategory::where('slug', 'sap')->value('name'));
    }

    public function test_roles_get_their_permissions(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $viewer = User::factory()->create()->assignRole(Role::Viewer->value);
        $manager = User::factory()->create()->assignRole(Role::ProjectManager->value);

        foreach (Permission::cases() as $permission) {
            $this->assertTrue($admin->can($permission->value), $permission->value);
        }

        $this->assertTrue($viewer->can(Permission::ProjectsViewAll->value));
        $this->assertFalse($viewer->can(Permission::ProjectsCreate->value));
        $this->assertTrue($manager->can(Permission::ProjectsCreate->value));
        $this->assertFalse($manager->can(Permission::ProjectsUpdateAll->value));
    }
}
