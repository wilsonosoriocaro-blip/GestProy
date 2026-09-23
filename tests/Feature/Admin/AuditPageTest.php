<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesProjectUsers;
use Tests\TestCase;

class AuditPageTest extends TestCase
{
    use CreatesProjectUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedProjectsModule();
    }

    public function test_leaders_audit_project_and_administration_changes(): void
    {
        $leader = $this->userWithRole(Role::Leader);
        $project = Project::factory()->create(['code' => 'TED-2026-042', 'status_id' => ProjectStatus::where('slug', 'planeado')->value('id')]);

        Livewire::actingAs($leader)->test('pages::projects.form', ['project' => $project])
            ->set('form.status_id', ProjectStatus::where('slug', 'en-ejecucion')->value('id'))
            ->call('save');

        $this->actingAs($leader);
        app(AuditLogger::class)->record('user.role_changed', $leader, $leader->name, ['role' => 'member'], ['role' => 'leader']);

        $page = Livewire::actingAs($leader)->test('pages::admin.audit')
            ->assertSee('TED-2026-042')
            ->assertSee('Cambio de estado')
            ->assertSee('En ejecución');

        $page->set('search', 'otro-proyecto')->assertSee('No hay registros con esos filtros.');

        $page->set('source', 'admin')
            ->assertSet('search', 'otro-proyecto')
            ->set('search', '')
            ->assertSee('Cambio de rol')
            ->assertSee('Líder de Tecnología y Estrategias Digitales');
    }

    public function test_access_requires_audit_permission(): void
    {
        $this->actingAs($this->userWithRole(Role::Leader))->get(route('admin.audit'))->assertOk();
        $this->actingAs($this->userWithRole(Role::ProjectManager))->get(route('admin.audit'))->assertForbidden();
    }
}
