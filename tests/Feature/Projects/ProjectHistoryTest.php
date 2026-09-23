<?php

namespace Tests\Feature\Projects;

use App\Enums\Role;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\CreatesProjectUsers;
use Tests\TestCase;

class ProjectHistoryTest extends TestCase
{
    use CreatesProjectUsers, RefreshDatabase;

    private User $leader;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedProjectsModule();
        $this->leader = $this->userWithRole(Role::Leader);
        $this->project = Project::factory()->manualProgress(10)->create([
            'status_id' => ProjectStatus::where('slug', 'planeado')->value('id'),
            'category_id' => ProjectCategory::where('slug', 'sap')->value('id'),
            'start_date' => '2026-09-01',
            'due_date' => '2026-12-15',
        ]);

        // Real changes through the edit form, so the history is the one users produce.
        Livewire::actingAs($this->leader)->test('pages::projects.form', ['project' => $this->project])
            ->set('form.status_id', ProjectStatus::where('slug', 'en-ejecucion')->value('id'))
            ->set('form.category_id', ProjectCategory::where('slug', 'integraciones')->value('id'))
            ->set('form.due_date', '2027-01-29')
            ->set('form.budget', '250000000')
            ->set('form.progress', 35)
            ->call('save')
            ->assertHasNoErrors();
    }

    private function history(User $user): Testable
    {
        return Livewire::actingAs($user)->test('projects.history', ['project' => $this->project]);
    }

    public function test_changes_are_shown_in_readable_form(): void
    {
        $this->history($this->leader)
            ->assertSee('Cambio de estado')
            ->assertSee('Planeado')
            ->assertSee('En ejecución')
            ->assertSee('Categoría')
            ->assertSee('SAP')
            ->assertSee('Integraciones')
            ->assertSee('15 dic. 2026')
            ->assertSee('29 ene. 2027')
            ->assertSee('Presupuesto')
            ->assertSee('250.000.000')
            ->assertSee('10%')
            ->assertSee('35%');
    }

    public function test_filters_by_group_person_and_dates(): void
    {
        $page = $this->history($this->leader);

        $page->set('group', 'dates')
            ->assertSee('Cambio de fechas')
            ->assertDontSee('Cambio de estado');

        $page->set('group', '')->set('user', (string) User::factory()->create()->id)
            ->assertSee('No hay cambios registrados con esos filtros.');

        $page->set('user', '')->set('from', now()->addDay()->toDateString())
            ->assertSee('No hay cambios registrados con esos filtros.');

        $page->call('clearFilters')->assertSee('Cambio de estado');
    }

    public function test_ip_address_is_only_visible_with_audit_permission(): void
    {
        $this->project->activityLogs()->update(['ip_address' => '10.20.30.40']);
        $member = $this->userWithRole(Role::Member);
        $this->project->members()->attach($member, ['role' => 'member']);

        $this->history($this->leader)->assertSee('10.20.30.40');
        $this->history($member)->assertDontSee('10.20.30.40');
    }

    public function test_outsiders_cannot_read_the_history(): void
    {
        $this->history($this->userWithRole(Role::Member))->assertForbidden();
    }

    public function test_project_page_tabs(): void
    {
        $this->actingAs($this->leader)->get(route('projects.show', ['project' => $this->project, 'tab' => 'history']))
            ->assertOk()->assertSee('Todos los cambios del proyecto');

        $this->actingAs($this->leader)->get(route('projects.show', ['project' => $this->project, 'tab' => 'log']))
            ->assertOk()->assertSee('Seguimiento del proyecto contado por el equipo');

        $this->actingAs($this->leader)->get(route('projects.show', ['project' => $this->project, 'tab' => 'details']))
            ->assertOk()->assertSee('Equipo');

        Livewire::actingAs($this->leader)->test('pages::projects.show', ['project' => $this->project])
            ->set('tab', 'nope')
            ->assertSet('tab', 'tasks');
    }
}
