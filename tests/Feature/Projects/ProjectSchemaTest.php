<?php

namespace Tests\Feature\Projects;

use App\Enums\ProjectMemberRole;
use App\Enums\ProjectStatusKind;
use App\Enums\ScheduleHealth;
use App\Enums\TaskStatusKind;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\ProjectTask;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_relations(): void
    {
        $project = Project::factory()->create();
        $member = User::factory()->create();
        $project->members()->attach($member, ['role' => ProjectMemberRole::Member->value]);

        $task = ProjectTask::factory()->for($project)->create(['assignee_id' => $member->id]);
        $subtask = ProjectTask::factory()->for($project)->create(['parent_id' => $task->id]);

        $project->load(['owner', 'category', 'status', 'priority', 'members', 'tasks', 'rootTasks']);

        $this->assertTrue($project->owner->is(User::find($project->owner_id)));
        $this->assertCount(1, $project->members);
        $this->assertSame(ProjectMemberRole::Member, $project->members->first()?->membership->role);
        $this->assertCount(2, $project->tasks);
        $this->assertCount(1, $project->rootTasks);
        $this->assertTrue($task->subtasks->first()?->is($subtask));
        $this->assertTrue($subtask->parent?->is($task));
        $this->assertCount(1, $member->assignedTasks);
        $this->assertCount(1, $member->memberProjects);
        $this->assertCount(1, $project->owner->ownedProjects);
    }

    public function test_participants_are_the_owner_and_the_team(): void
    {
        $project = Project::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $project->members()->attach($member, ['role' => ProjectMemberRole::Observer->value]);

        $this->assertTrue($project->isParticipant($project->owner));
        $this->assertTrue($project->isParticipant($member));
        $this->assertFalse($project->isParticipant($outsider));
    }

    public function test_task_dependencies(): void
    {
        $project = Project::factory()->create();
        [$design, $build] = ProjectTask::factory()->for($project)->count(2)->create();

        $build->dependencies()->attach($design, ['type' => 'FS']);

        $this->assertTrue($build->dependencies->first()?->is($design));
        $this->assertTrue($design->dependents->first()?->is($build));
    }

    public function test_project_exposes_its_schedule(): void
    {
        $project = Project::factory()
            ->withStatus(ProjectStatusKind::Active)
            ->between(CarbonImmutable::parse('2026-09-07'), CarbonImmutable::parse('2026-09-18'))
            ->create();

        $schedule = $project->schedule(CarbonImmutable::parse('2026-09-23'));

        $this->assertSame(ScheduleHealth::Overdue, $schedule->health);
        $this->assertSame(3, $schedule->overdueDays);
    }

    public function test_task_exposes_its_schedule(): void
    {
        $task = ProjectTask::factory()
            ->withStatus(TaskStatusKind::Completed)
            ->create([
                'start_date' => '2026-09-07',
                'due_date' => '2026-09-11',
                'completed_at' => '2026-09-15',
                'progress' => 100,
            ]);

        $this->assertSame(ScheduleHealth::CompletedLate, $task->schedule()->health);
    }

    public function test_projects_are_soft_deleted(): void
    {
        $project = Project::factory()->create();

        $project->delete();

        $this->assertSoftDeleted($project);
        $this->assertNull(Project::find($project->id));
    }

    public function test_project_code_is_unique(): void
    {
        Project::factory()->create(['code' => 'TED-001']);

        $this->expectException(QueryException::class);

        Project::factory()->create(['code' => 'TED-001']);
    }

    public function test_progress_must_be_between_0_and_100(): void
    {
        $this->expectException(QueryException::class);

        Project::factory()->create(['progress' => 101]);
    }

    public function test_due_date_cannot_be_before_start_date(): void
    {
        $this->expectException(QueryException::class);

        Project::factory()->between(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-09-01'))->create();
    }

    public function test_a_task_cannot_depend_on_itself(): void
    {
        $task = ProjectTask::factory()->create();

        $this->expectException(QueryException::class);

        $task->dependencies()->attach($task);
    }

    public function test_a_user_joins_a_project_team_only_once(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->create();
        $project->members()->attach($user, ['role' => 'member']);

        $this->expectException(QueryException::class);

        $project->members()->attach($user, ['role' => 'observer']);
    }

    public function test_a_status_in_use_cannot_be_deleted(): void
    {
        $project = Project::factory()->create();

        $this->expectException(QueryException::class);

        ProjectStatus::whereKey($project->status_id)->delete();
    }

    public function test_deleting_a_project_removes_its_tasks_members_and_log(): void
    {
        $project = Project::factory()->create();
        $project->members()->attach(User::factory()->create(), ['role' => 'member']);
        ProjectTask::factory()->for($project)->create();
        $project->activityLogs()->create(['event' => 'project.created']);

        $project->forceDelete();

        $this->assertDatabaseCount('project_tasks', 0);
        $this->assertDatabaseCount('project_members', 0);
        $this->assertDatabaseCount('project_activity_logs', 0);
    }
}
