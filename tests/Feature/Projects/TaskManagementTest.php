<?php

namespace Tests\Feature\Projects;

use App\Enums\ProjectActivityEvent;
use App\Enums\Role;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\CreatesProjectUsers;
use Tests\TestCase;

class TaskManagementTest extends TestCase
{
    use CreatesProjectUsers, RefreshDatabase;

    private User $owner;

    private User $member;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedProjectsModule();
        $this->travelTo(now()->setDate(2026, 9, 23));

        $this->owner = $this->userWithRole(Role::ProjectManager);
        $this->member = $this->userWithRole(Role::Member);
        $this->project = Project::factory()->create(['owner_id' => $this->owner->id]);
        $this->project->members()->attach($this->member, ['role' => 'member']);
    }

    private function statusId(string $slug): int
    {
        return ProjectTaskStatus::where('slug', $slug)->value('id');
    }

    private function form(User $user): Testable
    {
        return Livewire::actingAs($user)->test('projects.task-form', ['project' => $this->project]);
    }

    public function test_owner_creates_and_assigns_a_task(): void
    {
        $this->form($this->owner)
            ->call('open')
            ->set('form.name', 'Levantar requerimientos')
            ->set('form.assignee_id', (string) $this->member->id)
            ->set('form.start_date', '2026-09-21')
            ->set('form.due_date', '2026-10-02')
            ->set('form.progress', 40)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('task-saved');

        $task = ProjectTask::sole();
        $this->assertSame($this->member->id, $task->assignee_id);
        $this->assertSame($this->owner->id, $task->created_by);

        $events = $this->project->activityLogs()->pluck('event');
        $this->assertContains(ProjectActivityEvent::TaskCreated, $events);
        $this->assertContains(ProjectActivityEvent::TaskAssigned, $events);
        $this->assertContains(ProjectActivityEvent::ProgressChanged, $events);
        $this->assertSame(40, $this->project->refresh()->progress);
    }

    public function test_assignee_must_belong_to_the_project(): void
    {
        $outsider = User::factory()->create();

        $this->form($this->owner)
            ->call('open')
            ->set('form.name', 'Tarea')
            ->set('form.assignee_id', (string) $outsider->id)
            ->call('save')
            ->assertHasErrors(['form.assignee_id' => 'in']);
    }

    public function test_completing_a_task_updates_task_and_project(): void
    {
        $task = ProjectTask::factory()->for($this->project)->create(['progress' => 30, 'status_id' => $this->statusId('en-ejecucion')]);
        ProjectTask::factory()->for($this->project)->create(['progress' => 0, 'status_id' => $this->statusId('pendiente')]);

        $this->form($this->owner)
            ->call('open', $task->id)
            ->set('form.status_id', $this->statusId('finalizada'))
            ->call('save')
            ->assertHasNoErrors();

        $task->refresh();
        $this->assertSame(100, $task->progress);
        $this->assertSame('2026-09-23', $task->completed_at?->toDateString());
        $this->assertSame(50, $this->project->refresh()->progress);
        $this->assertTrue($this->project->activityLogs()->where('event', ProjectActivityEvent::TaskCompleted)->exists());
    }

    public function test_completing_a_task_with_a_real_finish_date(): void
    {
        $task = ProjectTask::factory()->for($this->project)->create(['progress' => 40, 'status_id' => $this->statusId('en-ejecucion')]);

        $this->form($this->owner)
            ->call('open', $task->id)
            ->set('form.status_id', $this->statusId('finalizada'))
            ->assertSeeHtml('wire:key="task-completed-at"')
            ->set('form.completed_at', '2026-09-22')
            ->call('save')
            ->assertHasNoErrors();

        $task->refresh();
        $this->assertSame(100, $task->progress);
        $this->assertSame('2026-09-22', $task->completed_at?->toDateString());
    }

    public function test_a_cleared_number_field_is_a_validation_error_not_a_crash(): void
    {
        $task = ProjectTask::factory()->for($this->project)->create(['status_id' => $this->statusId('en-ejecucion')]);

        $this->form($this->owner)
            ->call('open', $task->id)
            ->set('form.progress', '')
            ->set('form.weight', '')
            ->call('save')
            ->assertHasErrors(['form.progress', 'form.weight']);
    }

    public function test_changing_the_assignee_is_logged(): void
    {
        $task = ProjectTask::factory()->for($this->project)->create(['assignee_id' => $this->owner->id]);

        $this->form($this->owner)
            ->call('open', $task->id)
            ->set('form.assignee_id', (string) $this->member->id)
            ->call('save')
            ->assertHasNoErrors();

        $log = $this->project->activityLogs()->where('event', ProjectActivityEvent::TaskAssigned)->sole();
        $this->assertSame($task->id, $log->task_id);
        $this->assertSame($this->member->name, $log->new_values['assignee'] ?? null);
    }

    public function test_dependencies_reject_cycles_and_foreign_tasks(): void
    {
        [$design, $build] = ProjectTask::factory()->for($this->project)->count(2)->create();
        $build->dependencies()->attach($design);
        $foreign = ProjectTask::factory()->create();

        $this->form($this->owner)
            ->call('open', $design->id)
            ->set('form.dependencies', [(string) $build->id])
            ->call('save')
            ->assertHasErrors(['form.dependencies']);

        $this->form($this->owner)
            ->call('open', $build->id)
            ->set('form.dependencies', [(string) $design->id, (string) $foreign->id])
            ->call('save')
            ->assertHasErrors(['form.dependencies']);

        $this->form($this->owner)
            ->call('open', $build->id)
            ->assertSet('form.dependencies', [(string) $design->id])
            ->set('form.dependencies', [])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(0, $build->dependencies()->count());
    }

    public function test_assignee_reports_progress_but_cannot_edit_the_rest(): void
    {
        $task = ProjectTask::factory()->for($this->project)->create([
            'name' => 'Original',
            'assignee_id' => $this->member->id,
            'progress' => 10,
        ]);

        $this->form($this->member)
            ->call('open', $task->id)
            ->assertSet('mode', 'progress')
            ->set('form.name', 'Cambiado')
            ->set('form.weight', 50)
            ->set('form.progress', 60)
            ->set('form.status_id', $this->statusId('bloqueada'))
            ->set('form.notes', 'Esperando credenciales de SAP')
            ->call('save')
            ->assertHasNoErrors();

        $task->refresh();
        $this->assertSame('Original', $task->name);
        $this->assertSame(1, $task->weight);
        $this->assertSame(60, $task->progress);
        $this->assertSame('Esperando credenciales de SAP', $task->notes);
        $this->assertSame($this->statusId('bloqueada'), $task->status_id);
    }

    public function test_team_members_cannot_touch_tasks_assigned_to_others(): void
    {
        $task = ProjectTask::factory()->for($this->project)->create(['assignee_id' => $this->owner->id]);

        $this->form($this->member)->call('open', $task->id)->assertForbidden();
        $this->form($this->member)->call('open')->assertForbidden();
    }

    public function test_tasks_of_other_projects_cannot_be_opened(): void
    {
        $foreign = ProjectTask::factory()->create();

        $this->form($this->owner)->call('open', $foreign->id)->assertNotFound();
    }

    public function test_deleting_a_task_removes_subtasks_and_recalculates_progress(): void
    {
        $task = ProjectTask::factory()->for($this->project)->create(['progress' => 0]);
        $subtask = ProjectTask::factory()->for($this->project)->create(['parent_id' => $task->id]);
        ProjectTask::factory()->for($this->project)->create(['progress' => 80]);
        $this->project->update(['progress' => 40]);

        Livewire::actingAs($this->owner)->test('projects.tasks', ['project' => $this->project])
            ->call('confirmDelete', $task->id)
            ->call('delete')
            ->assertDispatched('task-saved');

        $this->assertSoftDeleted($task);
        $this->assertSoftDeleted($subtask);
        $this->assertSame(80, $this->project->refresh()->progress);
        $this->assertTrue($this->project->activityLogs()->where('event', ProjectActivityEvent::TaskDeleted)->exists());
    }

    public function test_members_cannot_delete_tasks(): void
    {
        $task = ProjectTask::factory()->for($this->project)->create(['assignee_id' => $this->member->id]);

        Livewire::actingAs($this->member)->test('projects.tasks', ['project' => $this->project])
            ->call('confirmDelete', $task->id)
            ->assertForbidden();
    }

    public function test_task_list_filters_and_history(): void
    {
        $late = ProjectTask::factory()->for($this->project)->create(['name' => 'Tarea vencida', 'status_id' => $this->statusId('en-ejecucion'), 'start_date' => '2026-09-01', 'due_date' => '2026-09-10']);
        $blocked = ProjectTask::factory()->for($this->project)->create(['name' => 'Tarea bloqueada', 'status_id' => $this->statusId('bloqueada'), 'start_date' => '2026-09-01', 'due_date' => '2026-12-10', 'progress' => 20]);
        $done = ProjectTask::factory()->for($this->project)->create(['name' => 'Tarea lista', 'status_id' => $this->statusId('finalizada'), 'start_date' => '2026-09-01', 'due_date' => '2026-09-10', 'progress' => 100]);

        $this->form($this->owner)->call('open', $blocked->id)->set('form.progress', 30)->call('save');

        $list = Livewire::actingAs($this->owner)->test('projects.tasks', ['project' => $this->project])
            ->assertSee('Tarea vencida')
            ->assertSee('Tarea lista');

        $list->call('focus', 'signal', 'overdue')
            ->assertSee('Tarea vencida')
            ->assertDontSee('Tarea bloqueada')
            ->assertDontSee('Tarea lista');

        $list->call('focus', 'signal', 'blocked')
            ->assertSee('Tarea bloqueada')
            ->assertDontSee('Tarea vencida');

        $list->call('clearFilters')->set('hideClosed', true)
            ->assertDontSee('Tarea lista');

        $list->call('showHistory', $blocked->id)
            ->assertSee('Tarea actualizada')
            ->assertSee('progress');

        $this->assertNotNull($late);
        $this->assertNotNull($done);
    }

    public function test_project_page_shows_the_task_section(): void
    {
        ProjectTask::factory()->for($this->project)->create(['name' => 'Configurar ambiente QA']);

        $this->actingAs($this->member)->get(route('projects.show', $this->project))
            ->assertOk()
            ->assertSee('Tareas')
            ->assertSee('Configurar ambiente QA')
            ->assertDontSee('wire:click="create"', false);

        $this->actingAs($this->owner)->get(route('projects.show', $this->project))
            ->assertSee('wire:click="create"', false);
    }
}
