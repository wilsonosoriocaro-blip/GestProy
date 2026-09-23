<?php

namespace Tests\Feature\Projects;

use App\Enums\Role;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesProjectUsers;
use Tests\TestCase;

class ProjectPolicyTest extends TestCase
{
    use CreatesProjectUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedProjectsModule();
    }

    public function test_leader_and_admin_manage_every_project(): void
    {
        $project = Project::factory()->create();

        foreach ([Role::Admin, Role::Leader] as $role) {
            $user = $this->userWithRole($role);

            foreach (['view', 'update', 'archive', 'delete'] as $ability) {
                $this->assertTrue($user->can($ability, $project), "{$role->value} {$ability}");
            }
        }
    }

    public function test_owner_edits_their_project_but_cannot_archive_or_delete_it(): void
    {
        $owner = $this->userWithRole(Role::ProjectManager);
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        $this->assertTrue($owner->can('view', $project));
        $this->assertTrue($owner->can('update', $project));
        $this->assertTrue($owner->can('manageMembers', $project));
        $this->assertFalse($owner->can('archive', $project));
        $this->assertFalse($owner->can('delete', $project));
    }

    public function test_team_members_see_the_project_without_editing_it(): void
    {
        $member = $this->userWithRole(Role::Member);
        $project = Project::factory()->create();
        $project->members()->attach($member, ['role' => 'member']);

        $this->assertTrue($member->can('view', $project));
        $this->assertFalse($member->can('update', $project));
    }

    public function test_outsiders_cannot_see_the_project(): void
    {
        $project = Project::factory()->create();

        $this->assertFalse($this->userWithRole(Role::Member)->can('view', $project));
        $this->assertFalse($this->userWithRole(Role::ProjectManager)->can('view', $project));
    }

    public function test_viewer_sees_everything_and_edits_nothing(): void
    {
        $viewer = $this->userWithRole(Role::Viewer);
        $project = Project::factory()->create();

        $this->assertTrue($viewer->can('view', $project));
        $this->assertFalse($viewer->can('create', Project::class));
        $this->assertFalse($viewer->can('update', $project));
    }

    public function test_archived_projects_are_read_only(): void
    {
        $leader = $this->userWithRole(Role::Leader);
        $project = Project::factory()->create(['archived_at' => now()]);

        $this->assertFalse($leader->can('update', $project));
        $this->assertFalse($leader->can('archive', $project));
        $this->assertTrue($leader->can('restore', $project));
    }

    public function test_only_roles_with_permission_create_projects(): void
    {
        $this->assertTrue($this->userWithRole(Role::ProjectManager)->can('create', Project::class));
        $this->assertFalse($this->userWithRole(Role::Member)->can('create', Project::class));
    }
}
