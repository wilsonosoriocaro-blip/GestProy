<?php

namespace Tests\Feature\Admin;

use App\Enums\ProjectStatusKind;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectPriority;
use App\Models\ProjectStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\CreatesProjectUsers;
use Tests\TestCase;

class CatalogAdministrationTest extends TestCase
{
    use CreatesProjectUsers, RefreshDatabase;

    private User $leader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedProjectsModule();
        $this->leader = $this->userWithRole(Role::Leader);
    }

    private function page(string $type = 'categories'): Testable
    {
        return Livewire::actingAs($this->leader)->test('pages::admin.catalogs')->set('type', $type);
    }

    public function test_access(): void
    {
        $this->actingAs($this->leader)->get(route('admin.catalogs'))->assertOk();
        $this->actingAs($this->userWithRole(Role::ProjectManager))->get(route('admin.catalogs'))->assertForbidden();
    }

    public function test_new_status_with_its_own_name_behaves_by_kind(): void
    {
        $this->page('project_statuses')
            ->call('create')
            ->set('form.name', 'En validación con negocio')
            ->set('form.kind', ProjectStatusKind::Active->value)
            ->set('form.color', 'violet')
            ->set('form.icon', 'eye')
            ->call('save')
            ->assertHasNoErrors();

        $status = ProjectStatus::where('name', 'En validación con negocio')->sole();
        $this->assertSame('en-validacion-con-negocio', $status->slug);
        $this->assertSame(ProjectStatusKind::Active, $status->kind);
        $this->assertSame('catalog.created', AuditLog::sole()->action);

        // Available right away in the project form.
        Livewire::actingAs($this->leader)->test('pages::projects.form')->assertSee('En validación con negocio');
    }

    public function test_only_one_default_and_it_stays_active(): void
    {
        $media = ProjectPriority::where('slug', 'media')->sole();
        $alta = ProjectPriority::where('slug', 'alta')->sole();

        $this->page('priorities')->call('edit', $alta->id)->set('form.is_default', true)->call('save')->assertHasNoErrors();

        $this->assertTrue($alta->fresh()->is_default);
        $this->assertFalse($media->fresh()->is_default);

        $this->page('priorities')->call('edit', $alta->id)->set('form.is_active', false)->call('save')
            ->assertHasErrors(['form.is_active']);
        $this->page('priorities')->call('edit', $alta->id)->set('form.is_default', false)->call('save')
            ->assertHasErrors(['form.is_default']);
    }

    public function test_kind_cannot_change_once_the_status_is_used(): void
    {
        $status = ProjectStatus::where('slug', 'en-ejecucion')->sole();
        Project::factory()->create(['status_id' => $status->id]);

        $this->page('project_statuses')->call('edit', $status->id)
            ->set('form.kind', ProjectStatusKind::Completed->value)
            ->call('save')
            ->assertHasErrors(['form.kind']);

        $this->assertSame(ProjectStatusKind::Active, $status->fresh()->kind);
    }

    public function test_entries_in_use_are_deactivated_not_deleted(): void
    {
        $used = ProjectCategory::where('slug', 'sap')->sole();
        Project::factory()->create(['category_id' => $used->id]);
        $unused = ProjectCategory::where('slug', 'otros')->sole();

        $this->page()->call('delete', $used->id)->assertHasErrors(['catalog']);
        $this->assertNotNull($used->fresh());

        $this->page()->call('delete', $unused->id)->assertHasNoErrors();
        $this->assertNull($unused->fresh());
        $this->assertSame('catalog.deleted', AuditLog::sole()->action);

        $this->page()->call('edit', $used->id)->set('form.is_active', false)->call('save')->assertHasNoErrors();
        $this->assertFalse($used->fresh()->is_active);
    }

    public function test_names_are_unique_per_catalog(): void
    {
        $this->page()->call('create')->set('form.name', 'SAP')->call('save')->assertHasErrors(['form.name' => 'unique']);
    }
}
