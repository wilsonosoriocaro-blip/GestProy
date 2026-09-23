<?php

namespace Tests\Feature\Projects;

use App\Enums\ProjectActivityEvent;
use App\Enums\Role;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectPriority;
use App\Models\ProjectStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesProjectUsers;
use Tests\TestCase;

class ProjectFormTest extends TestCase
{
    use CreatesProjectUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedProjectsModule();
        $this->travelTo(now()->setDate(2026, 9, 23));
    }

    private function statusId(string $slug): int
    {
        return ProjectStatus::where('slug', $slug)->value('id');
    }

    public function test_create_project(): void
    {
        $manager = $this->userWithRole(Role::ProjectManager);

        Livewire::actingAs($manager)->test('pages::projects.form')
            ->assertSet('form.owner_id', $manager->id)
            ->assertSet('form.status_id', $this->statusId('planeado'))
            ->set('form.name', 'Integración con SAP')
            ->set('form.category_id', ProjectCategory::where('slug', 'sap')->value('id'))
            ->set('form.start_date', '2026-10-01')
            ->set('form.due_date', '2026-12-15')
            ->set('form.budget', '150000000')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $project = Project::sole();
        $this->assertSame('TED-2026-001', $project->code);
        $this->assertSame($manager->id, $project->created_by);
        $this->assertSame('150000000.00', $project->budget);
        $this->assertSame(ProjectActivityEvent::ProjectCreated, $project->activityLogs()->sole()->event);
        $this->assertNotNull($project->last_activity_at);
    }

    public function test_codes_follow_a_yearly_sequence_and_accept_a_custom_code(): void
    {
        $manager = $this->userWithRole(Role::ProjectManager);
        Project::factory()->create(['code' => 'TED-2026-007']);

        $form = fn () => Livewire::actingAs($manager)->test('pages::projects.form')
            ->set('form.name', 'Proyecto')
            ->set('form.category_id', ProjectCategory::value('id'));

        $form()->call('save')->assertHasNoErrors();
        $form()->set('form.code', 'infra-01')->call('save')->assertHasNoErrors();

        $this->assertTrue(Project::where('code', 'TED-2026-008')->exists());
        $this->assertTrue(Project::where('code', 'INFRA-01')->exists());
    }

    public function test_validation(): void
    {
        $manager = $this->userWithRole(Role::ProjectManager);
        Project::factory()->create(['code' => 'TED-2026-001']);

        Livewire::actingAs($manager)->test('pages::projects.form')
            ->set('form.code', 'TED-2026-001')
            ->set('form.name', '')
            ->set('form.category_id', 999999)
            ->set('form.start_date', '2026-10-10')
            ->set('form.due_date', '2026-10-01')
            ->set('form.progress', 150)
            ->set('form.budget', '-5')
            ->call('save')
            ->assertHasErrors([
                'form.code' => 'unique',
                'form.name' => 'required',
                'form.category_id' => 'exists',
                'form.due_date' => 'after_or_equal',
                'form.progress' => 'between',
                'form.budget' => 'min',
            ]);

        $this->assertSame(1, Project::count());
    }

    public function test_update_logs_each_relevant_change(): void
    {
        $leader = $this->userWithRole(Role::Leader);
        $newOwner = User::factory()->create();
        $project = Project::factory()->create(['status_id' => $this->statusId('planeado')]);

        Livewire::actingAs($leader)->test('pages::projects.form', ['project' => $project])
            ->set('form.status_id', $this->statusId('en-ejecucion'))
            ->set('form.owner_id', $newOwner->id)
            ->set('form.due_date', '2027-03-31')
            ->set('form.name', 'Nombre nuevo')
            ->call('save')
            ->assertHasNoErrors();

        $events = $project->activityLogs()->pluck('event');

        $this->assertContains(ProjectActivityEvent::StatusChanged, $events);
        $this->assertContains(ProjectActivityEvent::OwnerChanged, $events);
        $this->assertContains(ProjectActivityEvent::DatesChanged, $events);
        $this->assertContains(ProjectActivityEvent::ProjectUpdated, $events);

        $statusLog = $project->activityLogs()->where('event', ProjectActivityEvent::StatusChanged)->sole();
        $this->assertSame('Planeado', $statusLog->old_values['status'] ?? null);
        $this->assertSame('En ejecución', $statusLog->new_values['status'] ?? null);
        $this->assertSame($leader->id, $statusLog->user_id);

        $project->refresh();
        $this->assertSame($newOwner->id, $project->owner_id);
        $this->assertSame($leader->id, $project->updated_by);
    }

    public function test_saving_without_changes_logs_nothing(): void
    {
        $leader = $this->userWithRole(Role::Leader);
        $project = Project::factory()->manualProgress(40)->create();

        Livewire::actingAs($leader)->test('pages::projects.form', ['project' => $project])->call('save')->assertHasNoErrors();

        $this->assertSame(0, $project->activityLogs()->count());
    }

    public function test_completing_a_project_sets_its_completion_data(): void
    {
        $leader = $this->userWithRole(Role::Leader);
        $project = Project::factory()->manualProgress(80)->create();

        Livewire::actingAs($leader)->test('pages::projects.form', ['project' => $project])
            ->set('form.status_id', $this->statusId('finalizado'))
            ->call('save')
            ->assertHasNoErrors();

        $project->refresh();
        $this->assertSame('2026-09-23', $project->completed_at?->toDateString());
        $this->assertSame(100, $project->progress);

        Livewire::actingAs($leader)->test('pages::projects.form', ['project' => $project])
            ->set('form.status_id', $this->statusId('en-ejecucion'))
            ->call('save');

        $this->assertNull($project->refresh()->completed_at);
    }

    public function test_task_based_progress_ignores_typed_values(): void
    {
        $leader = $this->userWithRole(Role::Leader);
        $project = Project::factory()->create();

        Livewire::actingAs($leader)->test('pages::projects.form', ['project' => $project])
            ->set('form.progress', 90)
            ->call('save');

        $this->assertSame(0, $project->refresh()->progress);
    }

    public function test_team_member_cannot_save_the_form(): void
    {
        $member = $this->userWithRole(Role::Member);
        $project = Project::factory()->create();
        $project->members()->attach($member, ['role' => 'member']);

        Livewire::actingAs($member)->test('pages::projects.form', ['project' => $project])->assertForbidden();
    }

    public function test_inactive_catalog_entries_cannot_be_chosen_for_new_projects(): void
    {
        $manager = $this->userWithRole(Role::ProjectManager);
        $inactive = ProjectPriority::factory()->create(['is_active' => false]);

        Livewire::actingAs($manager)->test('pages::projects.form')
            ->set('form.name', 'Proyecto')
            ->set('form.category_id', ProjectCategory::value('id'))
            ->set('form.priority_id', $inactive->id)
            ->call('save')
            ->assertHasErrors(['form.priority_id' => 'exists']);
    }
}
