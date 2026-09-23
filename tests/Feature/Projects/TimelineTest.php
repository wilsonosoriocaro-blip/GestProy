<?php

namespace Tests\Feature\Projects;

use App\Enums\Role;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\ProjectTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesProjectUsers;
use Tests\TestCase;

class TimelineTest extends TestCase
{
    use CreatesProjectUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedProjectsModule();
        $this->travelTo(now()->setDate(2026, 9, 23));
    }

    public function test_project_timeline_shows_tasks_and_dependencies(): void
    {
        $leader = $this->userWithRole(Role::Leader);
        $project = Project::factory()->create(['start_date' => '2026-09-01', 'due_date' => '2026-10-30']);
        $design = ProjectTask::factory()->for($project)->create(['name' => 'Diseño', 'start_date' => '2026-09-01', 'due_date' => '2026-09-11']);
        $build = ProjectTask::factory()->for($project)->create(['name' => 'Construcción', 'start_date' => '2026-09-14', 'due_date' => '2026-10-09']);
        $build->dependencies()->attach($design);

        $component = Livewire::actingAs($leader)->test('projects.timeline', ['project' => $project])
            ->assertSee('Cronograma')
            ->assertSee('Diseño')
            ->assertSee('Construcción')
            ->assertSee('Proyecto completo')
            ->assertSee('Dependencia');

        $this->assertCount(1, $component->instance()->chart['links']);
        $this->assertCount(3, $component->instance()->chart['rows']);

        $component->set('zoom', 'quarter')->assertSet('zoom', 'quarter');
        $component->set('zoom', 'bogus')->assertSet('zoom', 'week');
    }

    public function test_project_timeline_refreshes_when_tasks_change(): void
    {
        $leader = $this->userWithRole(Role::Leader);
        $project = Project::factory()->create();

        $component = Livewire::actingAs($leader)->test('projects.timeline', ['project' => $project]);
        ProjectTask::factory()->for($project)->create(['name' => 'Tarea nueva']);

        $component->dispatch('task-saved')->assertSee('Tarea nueva');
    }

    public function test_outsiders_cannot_load_a_project_timeline(): void
    {
        $project = Project::factory()->create();

        Livewire::actingAs($this->userWithRole(Role::Member))
            ->test('projects.timeline', ['project' => $project])
            ->assertForbidden();
    }

    public function test_project_page_includes_the_timeline(): void
    {
        $leader = $this->userWithRole(Role::Leader);
        $project = Project::factory()->create();

        $this->actingAs($leader)->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Duración, avance y dependencias de las tareas');
    }

    public function test_portfolio_timeline_respects_visibility_and_filters(): void
    {
        $manager = $this->userWithRole(Role::ProjectManager);
        $mine = Project::factory()->create(['owner_id' => $manager->id, 'name' => 'Mi proyecto']);
        $closed = Project::factory()->create([
            'owner_id' => $manager->id,
            'name' => 'Proyecto cerrado',
            'status_id' => ProjectStatus::where('slug', 'finalizado')->value('id'),
        ]);
        Project::factory()->create(['name' => 'Proyecto ajeno']);

        $page = Livewire::actingAs($manager)->test('pages::projects.timeline')
            ->assertSee('Mi proyecto')
            ->assertDontSee('Proyecto cerrado')
            ->assertDontSee('Proyecto ajeno');

        $page->set('includeClosed', true)->assertSee('Proyecto cerrado');

        $this->actingAs($manager)->get(route('projects.timeline'))->assertOk();
        $this->assertNotNull($mine);
        $this->assertNotNull($closed);
    }

    public function test_guests_cannot_see_the_portfolio_timeline(): void
    {
        $this->get(route('projects.timeline'))->assertRedirect(route('login'));
    }
}
