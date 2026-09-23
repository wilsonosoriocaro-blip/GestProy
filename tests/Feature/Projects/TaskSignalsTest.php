<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskStatus;
use App\Queries\Projects\TaskSignals;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesProjectUsers;
use Tests\TestCase;

/**
 * Reference date: Friday 2026-10-09. Monday Oct 12 is a holiday, so the
 * next five business days are Oct 9, 13, 14, 15 and 16.
 */
class TaskSignalsTest extends TestCase
{
    use CreatesProjectUsers, RefreshDatabase;

    private Project $project;

    private TaskSignals $signals;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedProjectsModule();
        $this->project = Project::factory()->create();
        $this->signals = TaskSignals::make(CarbonImmutable::parse('2026-10-09'));
    }

    private function task(string $status, array $attributes = []): ProjectTask
    {
        return ProjectTask::factory()->for($this->project)->create([
            'status_id' => ProjectTaskStatus::where('slug', $status)->value('id'),
            'start_date' => '2026-10-01',
            'due_date' => '2026-10-30',
            'progress' => 10,
            ...$attributes,
        ]);
    }

    /**
     * @return list<int>
     */
    private function ids(string $signal): array
    {
        $query = ProjectTask::query()->where('project_id', $this->project->id);
        $this->signals->apply($query, $signal);

        return $query->orderBy('id')->pluck('id')->all();
    }

    public function test_signals(): void
    {
        $overdue = $this->task('en-ejecucion', ['due_date' => '2026-10-08']);
        $overdueButDone = $this->task('finalizada', ['due_date' => '2026-10-08']);
        $dueSoon = $this->task('en-ejecucion', ['due_date' => '2026-10-16']);
        $notSoon = $this->task('en-ejecucion', ['due_date' => '2026-10-19']);
        $blocked = $this->task('bloqueada');
        $stalled = $this->task('pendiente', ['progress' => 0]);
        $notStartedYet = $this->task('pendiente', ['progress' => 0, 'start_date' => '2026-10-20']);

        $this->assertSame([$overdue->id], $this->ids(TaskSignals::OVERDUE));
        $this->assertSame([$dueSoon->id], $this->ids(TaskSignals::DUE_SOON));
        $this->assertSame([$blocked->id], $this->ids(TaskSignals::BLOCKED));
        $this->assertSame([$stalled->id], $this->ids(TaskSignals::WITHOUT_PROGRESS));

        $summary = $this->signals->summarize(TaskSignals::tasksOf($this->project->id));

        $this->assertSame(7, $summary['total']);
        $this->assertSame(1, $summary['by_kind']['completed']);
        $this->assertSame(2, $summary['by_kind']['pending']);
        $this->assertSame(['overdue' => 1, 'due_soon' => 1, 'blocked' => 1, 'without_progress' => 1], $summary['signals']);
        $this->assertNotContains($overdueButDone->id, $this->ids(TaskSignals::OVERDUE));
        $this->assertNotContains($notSoon->id, $this->ids(TaskSignals::DUE_SOON));
        $this->assertNotContains($notStartedYet->id, $this->ids(TaskSignals::WITHOUT_PROGRESS));
    }

    public function test_deleted_tasks_are_not_counted(): void
    {
        $this->task('en-ejecucion')->delete();

        $this->assertSame(0, $this->signals->summarize(TaskSignals::tasksOf($this->project->id))['total']);
    }
}
