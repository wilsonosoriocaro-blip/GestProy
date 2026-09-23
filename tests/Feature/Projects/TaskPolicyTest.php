<?php

namespace Tests\Feature\Projects;

use App\Enums\Role;
use App\Models\Project;
use App\Models\ProjectTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesProjectUsers;
use Tests\TestCase;

class TaskPolicyTest extends TestCase
{
    use CreatesProjectUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedProjectsModule();
    }

    public function test_project_owner_and_leader_manage_tasks(): void
    {
        $owner = $this->userWithRole(Role::ProjectManager);
        $project = Project::factory()->create(['owner_id' => $owner->id]);
        $task = ProjectTask::factory()->for($project)->create();

        foreach ([$owner, $this->userWithRole(Role::Leader)] as $user) {
            $this->assertTrue($user->can('create', [ProjectTask::class, $project]));
            $this->assertTrue($user->can('update', $task));
            $this->assertTrue($user->can('delete', $task));
        }
    }

    public function test_assignee_only_reports_progress(): void
    {
        $member = $this->userWithRole(Role::Member);
        $project = Project::factory()->create();
        $project->members()->attach($member, ['role' => 'member']);
        $mine = ProjectTask::factory()->for($project)->create(['assignee_id' => $member->id]);
        $other = ProjectTask::factory()->for($project)->create();

        $this->assertTrue($member->can('view', $mine));
        $this->assertTrue($member->can('updateProgress', $mine));
        $this->assertFalse($member->can('update', $mine));
        $this->assertFalse($member->can('delete', $mine));
        $this->assertFalse($member->can('updateProgress', $other));
        $this->assertFalse($member->can('create', [ProjectTask::class, $project]));
    }

    public function test_outsiders_cannot_see_tasks(): void
    {
        $task = ProjectTask::factory()->create();

        $this->assertFalse($this->userWithRole(Role::Member)->can('view', $task));
    }

    public function test_tasks_of_archived_projects_are_read_only(): void
    {
        $leader = $this->userWithRole(Role::Leader);
        $project = Project::factory()->create(['archived_at' => now()]);
        $task = ProjectTask::factory()->for($project)->create(['assignee_id' => $leader->id]);

        $this->assertTrue($leader->can('view', $task));
        $this->assertFalse($leader->can('create', [ProjectTask::class, $project]));
        $this->assertFalse($leader->can('update', $task));
        $this->assertFalse($leader->can('updateProgress', $task));
    }
}
