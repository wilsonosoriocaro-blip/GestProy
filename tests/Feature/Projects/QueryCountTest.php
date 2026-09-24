<?php

namespace Tests\Feature\Projects;

use App\Enums\Role;
use App\Models\Project;
use App\Models\ProjectComment;
use App\Models\ProjectStatus;
use App\Models\ProjectTask;
use App\Models\ProjectTaskStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\CreatesProjectUsers;
use Tests\TestCase;

/**
 * Guards against N+1 queries: every screen must run the same number of
 * queries whether the portfolio has a few projects or many.
 */
class QueryCountTest extends TestCase
{
    use CreatesProjectUsers, RefreshDatabase;

    private User $leader;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedProjectsModule();
        $this->leader = $this->userWithRole(Role::Leader);
        $this->admin = $this->userWithRole(Role::Admin);
    }

    /**
     * Fixed dates so every list (overdue, due soon, upcoming…) has rows in
     * both measurements: an empty list skips its eager-loading queries.
     */
    private function addProjects(int $count): Project
    {
        $team = User::factory()->count(3)->create();
        $projects = Project::factory()->count($count)->create([
            'status_id' => ProjectStatus::where('slug', 'en-ejecucion')->value('id'),
            'start_date' => today()->subDays(40),
            'due_date' => today()->addDays(2),
            'last_activity_at' => today()->subDays(30),
        ]);

        foreach ($projects as $project) {
            $project->members()->attach($team->pluck('id'), ['role' => 'member']);
            $tasks = collect([-5, 1, 10, 20])->map(fn (int $days) => ProjectTask::factory()->for($project)->create([
                'status_id' => ProjectTaskStatus::where('slug', 'en-ejecucion')->value('id'),
                'assignee_id' => $team->first()->id,
                'start_date' => today()->subDays(20),
                'due_date' => today()->addDays($days),
            ]));
            $tasks[1]->dependencies()->attach($tasks[0]);
            $tasks[2]->dependencies()->attach($tasks[1]);
            ProjectComment::factory()->for($project)->count(2)->create(['user_id' => $team->first()->id]);
        }

        return $projects->first();
    }

    /**
     * @return array<string, int>
     */
    private function measure(Project $project): array
    {
        $screens = [
            'projects' => fn () => Livewire::actingAs($this->leader)->test('pages::projects.index'),
            'dashboard' => fn () => Livewire::actingAs($this->leader)->test('pages::projects.dashboard'),
            'portfolio timeline' => fn () => Livewire::actingAs($this->leader)->test('pages::projects.timeline'),
            'project page' => fn () => Livewire::actingAs($this->leader)->test('pages::projects.show', ['project' => $project]),
            'tasks' => fn () => Livewire::actingAs($this->leader)->test('projects.tasks', ['project' => $project]),
            'project timeline' => fn () => Livewire::actingAs($this->leader)->test('projects.timeline', ['project' => $project]),
            'log' => fn () => Livewire::actingAs($this->leader)->test('projects.log', ['project' => $project]),
            'history' => fn () => Livewire::actingAs($this->leader)->test('projects.history', ['project' => $project]),
            'users' => fn () => Livewire::actingAs($this->admin)->test('pages::admin.users'),
            'catalogs' => fn () => Livewire::actingAs($this->leader)->test('pages::admin.catalogs')->set('type', 'priorities'),
            'audit' => fn () => Livewire::actingAs($this->leader)->test('pages::admin.audit'),
        ];

        $counts = [];

        foreach ($screens as $name => $render) {
            cache()->flush();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $render();
            $counts[$name] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }

        return $counts;
    }

    public function test_query_count_does_not_grow_with_the_portfolio(): void
    {
        $project = $this->addProjects(2);
        $this->measure($project); // warm-up: in-memory caches such as the permission registrar
        $few = $this->measure($project);

        $this->addProjects(8);
        $many = $this->measure($project);

        $this->assertSame($few, $many);
    }
}
