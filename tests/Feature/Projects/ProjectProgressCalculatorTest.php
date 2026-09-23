<?php

namespace Tests\Feature\Projects;

use App\Enums\TaskStatusKind;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Services\Projects\ProjectProgressCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectProgressCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private ProjectProgressCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new ProjectProgressCalculator;
    }

    public function test_project_without_tasks_has_no_progress(): void
    {
        $this->assertSame(0, $this->calculator->calculate(Project::factory()->create()));
    }

    public function test_progress_is_the_weighted_average_of_top_level_tasks(): void
    {
        $project = Project::factory()->create();

        ProjectTask::factory()->for($project)->create(['progress' => 50, 'weight' => 3]);
        ProjectTask::factory()->for($project)->create(['progress' => 10, 'weight' => 1]);

        // (50 * 3 + 10 * 1) / 4 = 40
        $this->assertSame(40, $this->calculator->calculate($project));
    }

    public function test_completed_tasks_count_as_done_and_cancelled_tasks_are_ignored(): void
    {
        $project = Project::factory()->create();

        ProjectTask::factory()->for($project)->withStatus(TaskStatusKind::Completed)->create(['progress' => 80]);
        ProjectTask::factory()->for($project)->create(['progress' => 0]);
        ProjectTask::factory()->for($project)->withStatus(TaskStatusKind::Cancelled)->create(['progress' => 0, 'weight' => 10]);

        $this->assertSame(50, $this->calculator->calculate($project));
    }

    public function test_subtasks_and_deleted_tasks_do_not_count_directly(): void
    {
        $project = Project::factory()->create();

        $parent = ProjectTask::factory()->for($project)->create(['progress' => 60]);
        ProjectTask::factory()->for($project)->create(['parent_id' => $parent->id, 'progress' => 0]);
        ProjectTask::factory()->for($project)->create(['progress' => 0])->delete();

        $this->assertSame(60, $this->calculator->calculate($project));
    }

    public function test_sync_updates_task_based_projects(): void
    {
        $project = Project::factory()->create();
        ProjectTask::factory()->for($project)->create(['progress' => 30]);

        $this->assertTrue($this->calculator->sync($project));
        $this->assertSame(30, $project->fresh()?->progress);
        $this->assertFalse($this->calculator->sync($project));
    }

    public function test_sync_never_overrides_manual_progress(): void
    {
        $project = Project::factory()->manualProgress(75)->create();
        ProjectTask::factory()->for($project)->create(['progress' => 10]);

        $this->assertFalse($this->calculator->sync($project));
        $this->assertSame(75, $project->fresh()?->progress);
    }
}
