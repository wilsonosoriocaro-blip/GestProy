<?php

namespace Tests\Feature\Projects;

use App\Enums\ProjectStatusKind;
use App\Enums\Role;
use App\Models\Project;
use App\Models\ProjectStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesProjectUsers;
use Tests\TestCase;

class ProjectPagesTest extends TestCase
{
    use CreatesProjectUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedProjectsModule();
    }

    private function statusId(string $slug): int
    {
        return ProjectStatus::where('slug', $slug)->value('id');
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('projects.index'))->assertRedirect(route('login'));
    }

    public function test_pages_render(): void
    {
        $leader = $this->userWithRole(Role::Leader);
        $project = Project::factory()->create();
        $this->actingAs($leader);

        $this->get(route('projects.index'))->assertOk()->assertSee($project->code);
        $this->get(route('projects.create'))->assertOk();
        $this->get(route('projects.show', $project))->assertOk()->assertSee($project->name);
        $this->get(route('projects.edit', $project))->assertOk();
    }

    public function test_pages_enforce_permissions(): void
    {
        $member = $this->userWithRole(Role::Member);
        $foreign = Project::factory()->create();
        $mine = Project::factory()->create();
        $mine->members()->attach($member, ['role' => 'member']);
        $this->actingAs($member);

        $this->get(route('projects.create'))->assertForbidden();
        $this->get(route('projects.show', $foreign))->assertForbidden();
        $this->get(route('projects.show', $mine))->assertOk();
        $this->get(route('projects.edit', $mine))->assertForbidden();
    }

    public function test_list_only_shows_projects_the_user_participates_in(): void
    {
        $manager = $this->userWithRole(Role::ProjectManager);
        $owned = Project::factory()->create(['owner_id' => $manager->id]);
        $shared = Project::factory()->create();
        $shared->members()->attach($manager, ['role' => 'observer']);
        $foreign = Project::factory()->create();

        Livewire::actingAs($manager)->test('pages::projects.index')
            ->assertSee($owned->code)
            ->assertSee($shared->code)
            ->assertDontSee($foreign->code);
    }

    public function test_search_matches_code_name_and_owner(): void
    {
        $leader = $this->userWithRole(Role::Leader);
        // Fixed descriptions: random lorem ipsum may contain words like "sapiente".
        $sap = Project::factory()->create(['name' => 'Migración SAP', 'description' => 'ERP']);
        $other = Project::factory()->create(['name' => 'Firewall', 'description' => 'Redes']);

        Livewire::actingAs($leader)->test('pages::projects.index')
            ->set('search', 'sap')
            ->assertSee($sap->code)
            ->assertDontSee($other->code)
            ->set('search', $other->owner->name)
            ->assertSee($other->code)
            ->set('search', '100%')
            ->assertDontSee($sap->code);
    }

    public function test_filters(): void
    {
        $leader = $this->userWithRole(Role::Leader);
        $today = CarbonImmutable::today();

        $late = Project::factory()->create(['status_id' => $this->statusId('en-ejecucion'), 'start_date' => $today->subDays(30), 'due_date' => $today->subDay()]);
        $risky = Project::factory()->create(['status_id' => $this->statusId('en-riesgo'), 'start_date' => $today, 'due_date' => $today->addMonth()]);
        $fine = Project::factory()->create(['status_id' => $this->statusId('en-ejecucion'), 'start_date' => $today, 'due_date' => $today->addMonth(), 'progress' => 60]);
        $finishedLate = Project::factory()->create(['status_id' => $this->statusId('finalizado'), 'start_date' => $today->subDays(30), 'due_date' => $today->subDay()]);
        $archived = Project::factory()->create(['archived_at' => now()]);

        $page = Livewire::actingAs($leader)->test('pages::projects.index')
            ->assertSee($fine->code)
            ->assertDontSee($archived->code);

        $page->set('overdue', true)
            ->assertSee($late->code)
            ->assertDontSee($risky->code)
            ->assertDontSee($fine->code)
            ->assertDontSee($finishedLate->code);

        $page->set('overdue', false)->set('atRisk', true)
            ->assertSee($late->code)
            ->assertSee($risky->code)
            ->assertDontSee($fine->code);

        $page->set('atRisk', false)->set('progress', '50-74')
            ->assertSee($fine->code)
            ->assertDontSee($late->code);

        $page->call('clearFilters')->set('status', (string) $this->statusId('en-riesgo'))
            ->assertSee($risky->code)
            ->assertDontSee($fine->code);

        $page->call('clearFilters')->set('archived', true)
            ->assertSee($archived->code)
            ->assertDontSee($fine->code);
    }

    public function test_sorting_ignores_unknown_columns(): void
    {
        Livewire::actingAs($this->userWithRole(Role::Leader))->test('pages::projects.index')
            ->call('sortBy', 'password')
            ->assertSet('sort', '')
            ->call('sortBy', 'progress')
            ->assertSet('sort', 'progress')
            ->call('sortBy', 'progress')
            ->assertSet('direction', 'desc');
    }

    public function test_show_page_reports_schedule_and_risk(): void
    {
        $leader = $this->userWithRole(Role::Leader);
        $project = Project::factory()->create([
            'status_id' => $this->statusId('en-ejecucion'),
            'start_date' => CarbonImmutable::today()->subMonth(),
            'due_date' => CarbonImmutable::today()->subWeek(),
        ]);

        $this->actingAs($leader)->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Atrasado')
            ->assertSee('Riesgo: Atrasado');
    }

    public function test_status_kinds_drive_the_overdue_filter_not_names(): void
    {
        $leader = $this->userWithRole(Role::Leader);
        $custom = ProjectStatus::factory()->kind(ProjectStatusKind::Active)->create(['name' => 'En validación con negocio']);
        $project = Project::factory()->create(['status_id' => $custom->id, 'start_date' => today()->subMonth(), 'due_date' => today()->subDay()]);

        Livewire::actingAs($leader)->test('pages::projects.index')
            ->set('overdue', true)
            ->assertSee($project->code);
    }
}
