<?php

namespace Tests\Feature\Projects;

use App\Enums\Role;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectStatus;
use App\Models\ProjectTask;
use App\Models\ProjectTaskStatus;
use App\Models\User;
use App\Queries\Projects\PortfolioDashboardQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesProjectUsers;
use Tests\TestCase;

/**
 * Reference date: Wednesday 2026-09-23.
 */
class PortfolioDashboardTest extends TestCase
{
    use CreatesProjectUsers, RefreshDatabase;

    private CarbonImmutable $today;

    private User $leader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedProjectsModule();
        $this->today = CarbonImmutable::parse('2026-09-23');
        $this->travelTo($this->today->setTime(10, 0));
        $this->leader = $this->userWithRole(Role::Leader);
    }

    private function project(string $status, string $start, string $due, int $progress, array $extra = []): Project
    {
        return Project::factory()->manualProgress($progress)->create([
            'status_id' => ProjectStatus::where('slug', $status)->value('id'),
            'start_date' => $start,
            'due_date' => $due,
            'last_activity_at' => $this->today,
            ...$extra,
        ]);
    }

    private function task(Project $project, string $status, string $due, array $extra = []): ProjectTask
    {
        return ProjectTask::factory()->for($project)->create([
            'status_id' => ProjectTaskStatus::where('slug', $status)->value('id'),
            'start_date' => '2026-09-01',
            'due_date' => $due,
            'progress' => 10,
            ...$extra,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(?User $user = null, array $filters = []): array
    {
        return app(PortfolioDashboardQuery::class)->build($user ?? $this->leader, $filters, $this->today);
    }

    public function test_project_indicators(): void
    {
        // Sep 7 → Oct 2: 20 business days, 12 elapsed → 60% expected.
        $onTrack = $this->project('en-ejecucion', '2026-09-07', '2026-10-02', 55);
        $behind = $this->project('en-ejecucion', '2026-09-07', '2026-10-02', 20);
        $late = $this->project('en-ejecucion', '2026-09-01', '2026-09-18', 90);
        $dueSoon = $this->project('en-ejecucion', '2026-09-07', '2026-09-29', 70);
        $declared = $this->project('en-riesgo', '2026-09-07', '2026-12-18', 30);
        $this->project('en-pausa', '2026-09-07', '2026-12-18', 10);
        $this->project('planeado', '2026-10-05', '2026-12-18', 0);
        $this->project('finalizado', '2026-08-03', '2026-09-11', 100, ['completed_at' => '2026-09-10']);
        $this->project('en-ejecucion', '2026-09-07', '2026-10-02', 90, ['archived_at' => now()]);

        $p = $this->data()['projects'];

        $this->assertSame(8, $p['total']);
        $this->assertSame(5, $p['active']);
        $this->assertSame(1, $p['paused']);
        $this->assertSame(1, $p['planned']);
        $this->assertSame(1, $p['completed']);
        $this->assertSame(1, $p['overdue']);
        $this->assertSame(1, $p['due_soon']);
        $this->assertSame(2, $p['at_risk']); // declared + past due
        $this->assertGreaterThanOrEqual(1, $p['behind']);

        $alerts = $this->data()['alerts'];
        $this->assertSame([$late->id], array_column($alerts['overdue_projects']['items'], 'id'));
        $this->assertContains($behind->id, array_column($alerts['behind_projects']['items'], 'id'));
        $this->assertNotContains($onTrack->id, array_column($alerts['behind_projects']['items'], 'id'));
        $this->assertNotNull($dueSoon);
        $this->assertNotNull($declared);
    }

    public function test_portfolio_progress_compares_real_and_expected(): void
    {
        $this->project('en-ejecucion', '2026-09-07', '2026-10-02', 50); // expected 60
        $this->project('en-ejecucion', '2026-09-07', '2026-10-02', 70); // expected 60
        $this->project('planeado', '2026-10-05', '2026-12-18', 0);      // not started: expected 0

        $progress = $this->data()['progress'];

        $this->assertSame(3, $progress['measured']);
        $this->assertSame(40, $progress['real']);
        $this->assertSame(40, $progress['expected']);

        $gap = $this->data()['progress_gap'];
        $this->assertSame(-10, $gap[0]['gap']);
    }

    public function test_stale_projects(): void
    {
        $stale = $this->project('en-ejecucion', '2026-08-03', '2026-12-18', 40, ['last_activity_at' => '2026-09-01 09:00']);
        $this->project('en-ejecucion', '2026-08-03', '2026-12-18', 40, ['last_activity_at' => '2026-09-21 09:00']);
        $this->project('en-pausa', '2026-08-03', '2026-12-18', 40, ['last_activity_at' => '2026-08-01 09:00']);

        $alerts = $this->data()['alerts']['stale_projects'];

        $this->assertSame(1, $alerts['count']);
        $this->assertSame($stale->id, $alerts['items'][0]['id']);
    }

    public function test_task_indicators_and_workload(): void
    {
        $project = $this->project('en-ejecucion', '2026-09-01', '2026-12-18', 30);
        $ana = User::factory()->create(['name' => 'Ana']);
        $project->members()->attach($ana, ['role' => 'member']);

        $this->task($project, 'en-ejecucion', '2026-09-18', ['assignee_id' => $ana->id]);
        $this->task($project, 'en-ejecucion', '2026-09-25', ['assignee_id' => $ana->id]);
        $this->task($project, 'bloqueada', '2026-11-30', ['assignee_id' => $ana->id]);
        $this->task($project, 'finalizada', '2026-09-10', ['assignee_id' => $ana->id, 'progress' => 100]);
        $this->task($project, 'pendiente', '2026-11-30');

        $data = $this->data();

        $this->assertSame(5, $data['tasks']['total']);
        $this->assertSame(1, $data['tasks']['signals']['overdue']);
        $this->assertSame(1, $data['tasks']['signals']['due_soon']);
        $this->assertSame(1, $data['tasks']['signals']['blocked']);
        $this->assertSame(1, $data['alerts']['overdue_tasks']['count']);

        $this->assertSame([[
            'user_id' => $ana->id,
            'name' => 'Ana',
            'open' => 3,
            'overdue' => 1,
            'owned_projects' => 0,
        ]], $data['workload']);
    }

    public function test_upcoming_deadlines_mix_projects_and_tasks_by_date(): void
    {
        $project = $this->project('en-ejecucion', '2026-09-01', '2026-10-15', 30);
        $this->task($project, 'en-ejecucion', '2026-09-25', ['name' => 'Pruebas']);
        $this->task($project, 'en-ejecucion', '2026-12-01', ['name' => 'Muy lejos']);
        $this->task($project, 'finalizada', '2026-09-24', ['name' => 'Ya lista']);

        $upcoming = $this->data()['upcoming'];

        $this->assertSame(['Pruebas', $project->name], array_column($upcoming, 'name'));
        $this->assertSame(['task', 'project'], array_column($upcoming, 'type'));
        $this->assertSame(3, $upcoming[0]['remaining']); // Sep 23, 24, 25
    }

    public function test_members_only_see_their_projects(): void
    {
        $member = $this->userWithRole(Role::Member);
        $mine = $this->project('en-ejecucion', '2026-09-01', '2026-09-18', 10);
        $mine->members()->attach($member, ['role' => 'member']);
        $this->project('en-ejecucion', '2026-09-01', '2026-09-18', 10);
        $this->task($mine, 'en-ejecucion', '2026-09-18');

        $data = $this->data($member);

        $this->assertSame(1, $data['projects']['total']);
        $this->assertSame(1, $data['projects']['overdue']);
        $this->assertSame(1, $data['tasks']['total']);
    }

    public function test_category_filter(): void
    {
        $sap = ProjectCategory::where('slug', 'sap')->value('id');
        $this->project('en-ejecucion', '2026-09-01', '2026-12-18', 10, ['category_id' => $sap]);
        $this->project('en-ejecucion', '2026-09-01', '2026-12-18', 10);

        $this->assertSame(1, $this->data(filters: ['category' => $sap])['projects']['total']);
        $this->assertSame(2, $this->data()['projects']['total']);
    }

    public function test_dashboard_page_renders_and_refresh_skips_the_cache(): void
    {
        $this->project('en-ejecucion', '2026-09-01', '2026-09-18', 10, ['name' => 'Portal de proveedores']);

        $page = Livewire::actingAs($this->leader)->test('pages::projects.dashboard')
            ->assertSee('Tablero ejecutivo')
            ->assertSee('Requiere atención')
            ->assertSee('Portal de proveedores');

        $this->project('en-ejecucion', '2026-09-01', '2026-09-18', 10, ['name' => 'Nuevo proyecto atrasado']);

        $page->assertDontSee('Nuevo proyecto atrasado'); // still cached
        $page->call('refresh')->assertSee('Nuevo proyecto atrasado');

        $this->actingAs($this->leader)->get(route('dashboard'))->assertOk();
    }

    public function test_empty_portfolio(): void
    {
        Livewire::actingAs($this->leader)->test('pages::projects.dashboard')
            ->assertOk()
            ->assertSee('No hay proyectos activos con fechas para comparar.')
            ->assertSee('Todo en orden');
    }
}
