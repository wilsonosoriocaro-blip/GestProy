<?php

namespace Tests\Feature\Projects;

use App\Enums\Role;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\ProjectTask;
use App\Models\ProjectTaskStatus;
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

    public function test_timeline_tab_loads_only_when_opened(): void
    {
        $leader = $this->userWithRole(Role::Leader);
        $project = Project::factory()->create();

        $this->actingAs($leader)->get(route('projects.show', $project))
            ->assertOk()
            ->assertDontSee('Duración, avance y dependencias de las tareas');

        $this->actingAs($leader)->get(route('projects.show', ['project' => $project, 'tab' => 'timeline']))
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

    public function test_selecting_a_person_with_show_tasks_adds_their_workload_in_other_projects(): void
    {
        // Needs to see the whole portfolio (ProjectsViewAll), not just what they own.
        $viewer = $this->userWithRole(Role::Leader);
        $member = $this->userWithRole(Role::Member);
        $teammate = $this->userWithRole(Role::Member);

        $own = Project::factory()->create(['owner_id' => $member->id, 'name' => 'Proyecto de Camila']);
        ProjectTask::factory()->for($own)->create(['name' => 'Tarea propia sin asignar', 'assignee_id' => null]);

        $foreign = Project::factory()->create(['owner_id' => $teammate->id, 'name' => 'Proyecto de Andrés']);
        ProjectTask::factory()->for($foreign)->create(['name' => 'Tarea prestada', 'assignee_id' => $member->id]);
        ProjectTask::factory()->for($foreign)->create(['name' => 'Tarea de otro compañero', 'assignee_id' => $teammate->id]);

        $page = Livewire::actingAs($viewer)->test('pages::projects.timeline')
            ->set('owner', (string) $member->id)
            ->set('showTasks', true)
            ->assertSee('Proyecto de Camila')
            ->assertSee('Proyecto de Andrés')
            ->assertSee('Tarea prestada')
            // Only Camila's own tasks show under the foreign project, not her teammate's.
            ->assertDontSee('Tarea de otro compañero')
            // Tasks nobody assigned don't get pulled into her workload.
            ->assertDontSee('Tarea propia sin asignar');

        $rows = collect($page->instance()->chart['rows'])->keyBy('label');
        $this->assertNull($rows['Proyecto de Camila']['color']);
        $this->assertNotNull($rows['Proyecto de Andrés']['color']);
        $this->assertSame($rows['Proyecto de Andrés']['color'], $rows['Tarea prestada']['color']);
    }

    public function test_picking_a_project_breaks_it_down_into_tasks_colored_by_compliance(): void
    {
        $viewer = $this->userWithRole(Role::Leader);
        $project = Project::factory()->create(['name' => 'Desarrollo módulo de Aduana', 'start_date' => '2026-09-01', 'due_date' => '2026-10-30']);
        Project::factory()->create(['name' => 'Otro proyecto']);

        $running = ProjectTaskStatus::where('slug', 'en-ejecucion')->value('id');
        $done = ProjectTaskStatus::where('slug', 'finalizada')->value('id');
        ProjectTask::factory()->for($project)->create(['name' => 'Al día', 'status_id' => $running, 'start_date' => '2026-09-21', 'due_date' => '2026-10-16', 'progress' => 20]);
        ProjectTask::factory()->for($project)->create(['name' => 'Vencida', 'status_id' => $running, 'start_date' => '2026-09-01', 'due_date' => '2026-09-15', 'progress' => 50]);
        ProjectTask::factory()->for($project)->create(['name' => 'Terminada', 'status_id' => $done, 'start_date' => '2026-09-01', 'due_date' => '2026-09-10', 'progress' => 100, 'completed_at' => '2026-09-09']);

        $page = Livewire::actingAs($viewer)->test('pages::projects.timeline')
            ->set('project', (string) $project->id)
            ->assertSee('Desarrollo módulo de Aduana')
            ->assertSee('Cumpliendo')
            ->assertSee('Incumpliendo')
            ->assertSee('Tarea incumpliendo');

        $rows = collect($page->instance()->chart['rows'])->keyBy('label');
        // The project first, then only its own tasks (never "Otro proyecto").
        $this->assertSame('Desarrollo módulo de Aduana', $rows->keys()->first());
        $this->assertEqualsCanonicalizing(['Desarrollo módulo de Aduana', 'Al día', 'Vencida', 'Terminada'], $rows->keys()->all());
        $this->assertNull($rows['Desarrollo módulo de Aduana']['compliance']);
        $this->assertSame('failing', $rows['Vencida']['compliance']);
        $this->assertSame('meeting', $rows['Terminada']['compliance']);
        $this->assertSame(1, $page->instance()->compliance['failing']);
        $this->assertSame(3, array_sum($page->instance()->compliance));
    }

    public function test_without_a_project_the_timeline_has_no_compliance_colors(): void
    {
        $viewer = $this->userWithRole(Role::Leader);
        $project = Project::factory()->create();
        ProjectTask::factory()->for($project)->create();

        $page = Livewire::actingAs($viewer)->test('pages::projects.timeline')
            ->set('showTasks', true)
            ->assertDontSee('Tarea incumpliendo');

        $this->assertSame([], array_filter(array_column($page->instance()->chart['rows'], 'compliance')));
    }

    public function test_a_project_the_user_cannot_see_is_ignored(): void
    {
        $manager = $this->userWithRole(Role::ProjectManager);
        $mine = Project::factory()->create(['owner_id' => $manager->id, 'name' => 'Mi proyecto']);
        $foreign = Project::factory()->create(['name' => 'Proyecto ajeno']);

        $page = Livewire::actingAs($manager)->test('pages::projects.timeline')
            ->set('project', (string) $foreign->id)
            ->assertDontSee('Proyecto ajeno')
            ->assertSee('Mi proyecto');

        // Falls back to the normal portfolio view.
        $this->assertSame(['Mi proyecto'], array_column($page->instance()->chart['rows'], 'label'));
        $this->assertNotNull($mine);
    }

    public function test_the_person_picker_also_lists_people_with_no_project_of_their_own(): void
    {
        $manager = $this->userWithRole(Role::ProjectManager);
        $assigneeOnly = $this->userWithRole(Role::Member);
        $project = Project::factory()->create(['owner_id' => $manager->id]);
        ProjectTask::factory()->for($project)->create(['assignee_id' => $assigneeOnly->id]);

        Livewire::actingAs($manager)->test('pages::projects.timeline')
            ->assertSee($assigneeOnly->name);
    }
}
