<?php

namespace Tests\Feature\Projects;

use App\Enums\ProjectActivityEvent;
use App\Enums\Role;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesProjectUsers;
use Tests\TestCase;

class ProjectActionsTest extends TestCase
{
    use CreatesProjectUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedProjectsModule();
    }

    public function test_leader_archives_and_restores_a_project(): void
    {
        $leader = $this->userWithRole(Role::Leader);
        $project = Project::factory()->create();

        $page = Livewire::actingAs($leader)->test('pages::projects.show', ['project' => $project])->call('archive');
        $this->assertTrue($project->refresh()->isArchived());

        $page->call('restore');
        $this->assertFalse($project->refresh()->isArchived());

        $events = $project->activityLogs()->pluck('event');
        $this->assertContains(ProjectActivityEvent::ProjectArchived, $events);
        $this->assertContains(ProjectActivityEvent::ProjectRestored, $events);
    }

    public function test_leader_deletes_a_project(): void
    {
        $leader = $this->userWithRole(Role::Leader);
        $project = Project::factory()->create();

        Livewire::actingAs($leader)->test('pages::projects.show', ['project' => $project])
            ->call('delete')
            ->assertRedirect(route('projects.index'));

        $this->assertSoftDeleted($project);
        $this->assertDatabaseHas('project_activity_logs', ['project_id' => $project->id, 'event' => ProjectActivityEvent::ProjectDeleted->value]);
    }

    public function test_owner_cannot_archive_or_delete(): void
    {
        $owner = $this->userWithRole(Role::ProjectManager);
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        Livewire::actingAs($owner)->test('pages::projects.show', ['project' => $project])
            ->call('archive')->assertForbidden();

        Livewire::actingAs($owner)->test('pages::projects.show', ['project' => $project])
            ->call('delete')->assertForbidden();

        $this->assertFalse($project->refresh()->isArchived());
        $this->assertNotSoftDeleted($project);
    }

    public function test_owner_manages_the_team(): void
    {
        $owner = $this->userWithRole(Role::ProjectManager);
        $project = Project::factory()->create(['owner_id' => $owner->id]);
        $colleague = User::factory()->create();

        $team = Livewire::actingAs($owner)->test('projects.team', ['project' => $project])
            ->set('userId', $colleague->id)
            ->set('role', 'observer')
            ->call('add')
            ->assertHasNoErrors()
            ->assertSee($colleague->name);

        $this->assertSame('observer', $project->memberships()->sole()->role->value);

        $team->call('remove', $colleague->id);
        $this->assertSame(0, $project->memberships()->count());

        $events = $project->activityLogs()->pluck('event');
        $this->assertContains(ProjectActivityEvent::MemberAdded, $events);
        $this->assertContains(ProjectActivityEvent::MemberRemoved, $events);
    }

    public function test_owner_cannot_be_added_as_member(): void
    {
        $owner = $this->userWithRole(Role::ProjectManager);
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        Livewire::actingAs($owner)->test('projects.team', ['project' => $project])
            ->set('userId', $owner->id)
            ->call('add')
            ->assertHasErrors(['userId']);
    }

    public function test_members_cannot_change_the_team(): void
    {
        $member = $this->userWithRole(Role::Member);
        $project = Project::factory()->create();
        $project->members()->attach($member, ['role' => 'member']);

        Livewire::actingAs($member)->test('projects.team', ['project' => $project])
            ->set('userId', User::factory()->create()->id)
            ->call('add')
            ->assertForbidden();
    }
}
