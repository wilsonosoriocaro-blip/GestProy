<?php

namespace Tests\Feature\Projects;

use App\Enums\ProjectActivityEvent;
use App\Enums\Role;
use App\Models\Project;
use App\Models\ProjectComment;
use App\Models\ProjectTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\CreatesProjectUsers;
use Tests\TestCase;

class ProjectLogTest extends TestCase
{
    use CreatesProjectUsers, RefreshDatabase;

    private User $owner;

    private User $member;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedProjectsModule();
        $this->owner = $this->userWithRole(Role::ProjectManager);
        $this->member = $this->userWithRole(Role::Member);
        $this->project = Project::factory()->create(['owner_id' => $this->owner->id]);
        $this->project->members()->attach($this->member, ['role' => 'member']);
    }

    private function log(User $user): Testable
    {
        return Livewire::actingAs($user)->test('projects.log', ['project' => $this->project]);
    }

    public function test_policy(): void
    {
        $viewer = $this->userWithRole(Role::Viewer);
        $leader = $this->userWithRole(Role::Leader);
        $comment = ProjectComment::factory()->for($this->project)->create(['user_id' => $this->member->id]);

        $this->assertTrue($this->member->can('create', [ProjectComment::class, $this->project]));
        $this->assertTrue($leader->can('create', [ProjectComment::class, $this->project]));
        $this->assertFalse($viewer->can('create', [ProjectComment::class, $this->project]));
        $this->assertTrue($viewer->can('viewAny', [ProjectComment::class, $this->project]));

        $this->assertTrue($this->member->can('update', $comment));
        $this->assertFalse($this->owner->can('update', $comment));
        $this->assertTrue($this->owner->can('delete', $comment));
        $this->assertTrue($this->owner->can('highlight', [ProjectComment::class, $this->project]));
        $this->assertFalse($this->member->can('highlight', [ProjectComment::class, $this->project]));

        $this->project->forceFill(['archived_at' => now()])->save();
        $this->assertFalse($this->member->can('create', [ProjectComment::class, $this->project->refresh()]));
    }

    public function test_team_member_writes_the_log(): void
    {
        $task = ProjectTask::factory()->for($this->project)->create(['name' => 'Integración SAP']);

        $this->log($this->member)
            ->set('body', 'Se finalizó la integración con SAP y se inició la fase de pruebas con usuarios.')
            ->set('taskId', (string) $task->id)
            ->set('highlighted', true) // not allowed for members: ignored
            ->call('add')
            ->assertHasNoErrors()
            ->assertSee('Se finalizó la integración con SAP')
            ->assertSee('Integración SAP');

        $comment = ProjectComment::sole();
        $this->assertSame($this->member->id, $comment->user_id);
        $this->assertSame($task->id, $comment->task_id);
        $this->assertFalse($comment->is_highlighted);

        $log = $this->project->activityLogs()->sole();
        $this->assertSame(ProjectActivityEvent::CommentAdded, $log->event);
        $this->assertNotNull($this->project->refresh()->last_activity_at);
    }

    public function test_owner_highlights_entries_and_filters_by_them(): void
    {
        $this->log($this->owner)
            ->set('body', 'Salida en vivo aprobada por el comité')
            ->set('highlighted', true)
            ->call('add');

        ProjectComment::factory()->for($this->project)->create(['user_id' => $this->member->id, 'body' => 'Nota de seguimiento semanal']);

        $this->log($this->owner)
            ->assertSee('Nota de seguimiento semanal')
            ->set('onlyHighlighted', true)
            ->assertSee('Salida en vivo aprobada por el comité')
            ->assertDontSee('Nota de seguimiento semanal');

        $normal = ProjectComment::where('body', 'Nota de seguimiento semanal')->sole();
        $this->log($this->owner)->call('toggleHighlight', $normal->id);
        $this->assertTrue($normal->refresh()->is_highlighted);

        $this->log($this->member)->call('toggleHighlight', $normal->id)->assertForbidden();
    }

    public function test_validation(): void
    {
        $foreignTask = ProjectTask::factory()->create();

        $this->log($this->member)
            ->set('body', '')
            ->set('taskId', (string) $foreignTask->id)
            ->call('add')
            ->assertHasErrors(['body' => 'required', 'taskId' => 'exists']);

        $this->assertSame(0, ProjectComment::count());
    }

    public function test_authors_edit_their_entries_and_others_cannot(): void
    {
        $comment = ProjectComment::factory()->for($this->project)->create(['user_id' => $this->member->id, 'body' => 'Texto original']);

        $this->log($this->member)
            ->call('startEdit', $comment->id)
            ->assertSet('editingBody', 'Texto original')
            ->set('editingBody', 'Texto corregido')
            ->call('saveEdit')
            ->assertHasNoErrors();

        $this->assertSame('Texto corregido', $comment->refresh()->body);
        $edit = $this->project->activityLogs()->where('event', ProjectActivityEvent::CommentUpdated)->sole();
        $this->assertSame('Texto original', $edit->old_values['body'] ?? null);

        $this->log($this->owner)->call('startEdit', $comment->id)->assertForbidden();
    }

    public function test_owner_deletes_entries_and_viewers_cannot_write(): void
    {
        $comment = ProjectComment::factory()->for($this->project)->create(['user_id' => $this->member->id]);

        $this->log($this->owner)->call('delete', $comment->id);
        $this->assertSoftDeleted($comment);
        $this->assertTrue($this->project->activityLogs()->where('event', ProjectActivityEvent::CommentDeleted)->exists());

        $this->log($this->userWithRole(Role::Viewer))
            ->assertDontSee('Nueva actualización')
            ->set('body', 'Intento')
            ->call('add')
            ->assertForbidden();
    }

    public function test_load_more(): void
    {
        ProjectComment::factory()->for($this->project)->count(18)->create(['user_id' => $this->member->id]);

        $component = $this->log($this->owner)->assertSee('Ver más');
        $this->assertCount(16, $component->instance()->entries);

        $component->call('loadMore')->assertDontSee('Ver más');
    }
}
