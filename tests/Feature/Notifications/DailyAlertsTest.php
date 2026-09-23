<?php

namespace Tests\Feature\Notifications;

use App\Enums\Role;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\ProjectTask;
use App\Models\ProjectTaskStatus;
use App\Models\User;
use App\Notifications\Projects\DailyAlertsNotification;
use App\Services\Notifications\DailyAlertsBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesProjectUsers;
use Tests\TestCase;

/**
 * Reference date: Wednesday 2026-09-23.
 */
class DailyAlertsTest extends TestCase
{
    use CreatesProjectUsers, RefreshDatabase;

    private User $owner;

    private User $member;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedProjectsModule();
        $this->travelTo(CarbonImmutable::parse('2026-09-23 07:00'));
        Notification::fake();

        $this->owner = $this->userWithRole(Role::ProjectManager);
        $this->member = $this->userWithRole(Role::Member);
        $this->project = Project::factory()->create([
            'owner_id' => $this->owner->id,
            'status_id' => ProjectStatus::where('slug', 'en-ejecucion')->value('id'),
            'start_date' => '2026-08-01',
            'due_date' => '2026-12-18',
        ]);
    }

    private function task(string $status, string $due, ?User $assignee = null, ?Project $project = null): ProjectTask
    {
        return ProjectTask::factory()->for($project ?? $this->project)->create([
            'status_id' => ProjectTaskStatus::where('slug', $status)->value('id'),
            'start_date' => '2026-09-01',
            'due_date' => $due,
            'assignee_id' => $assignee?->id,
        ]);
    }

    public function test_digest_groups_work_by_person(): void
    {
        $this->task('en-ejecucion', '2026-09-18', $this->member);   // overdue since Friday: Sep 21, 22 and 23 → member
        $this->task('en-ejecucion', '2026-09-25', $this->member);   // due soon → member
        $this->task('finalizada', '2026-09-18', $this->member);     // closed → nobody
        $this->task('pendiente', '2026-09-21');                     // overdue, unassigned → owner
        $this->task('en-ejecucion', '2026-11-30', $this->member);   // not yet → nobody

        $late = Project::factory()->create([
            'owner_id' => $this->owner->id,
            'status_id' => ProjectStatus::where('slug', 'en-riesgo')->value('id'),
            'start_date' => '2026-08-01',
            'due_date' => '2026-09-21',
        ]);
        Project::factory()->create(['owner_id' => $this->owner->id, 'due_date' => '2026-09-10', 'start_date' => '2026-08-01', 'archived_at' => now()]);

        $digests = app(DailyAlertsBuilder::class)->build(CarbonImmutable::today());

        $this->assertCount(1, $digests[$this->member->id]['tasks_overdue']);
        $this->assertCount(1, $digests[$this->member->id]['tasks_due_soon']);
        $this->assertStringContainsString('3 días hábiles de retraso', $digests[$this->member->id]['tasks_overdue'][0]['detail']);
        $this->assertCount(1, $digests[$this->owner->id]['tasks_overdue']);
        $this->assertSame([$late->code.' · '.$late->name], array_column($digests[$this->owner->id]['projects_overdue'], 'label'));
    }

    public function test_command_sends_one_digest_per_person_and_never_twice_a_day(): void
    {
        $this->task('en-ejecucion', '2026-09-18', $this->member);

        $this->artisan('projects:send-alerts')->expectsOutputToContain('Resúmenes enviados: 1.')->assertSuccessful();
        $this->artisan('projects:send-alerts')->expectsOutputToContain('Resúmenes enviados: 0.')->assertSuccessful();

        Notification::assertSentToTimes($this->member, DailyAlertsNotification::class, 1);
        Notification::assertSentTo($this->member, DailyAlertsNotification::class, function (DailyAlertsNotification $notification) {
            return $notification->level() === 'warning'
                && $notification->body() === 'Tareas vencidas: 1.';
        });
        $this->assertDatabaseHas('project_alert_digests', ['user_id' => $this->member->id, 'digest_date' => '2026-09-23', 'items' => 1]);
    }

    public function test_no_digest_on_holidays_unless_forced(): void
    {
        $this->task('en-ejecucion', '2026-10-09', $this->member);

        $this->artisan('projects:send-alerts', ['--date' => '2026-10-12'])
            ->expectsOutputToContain('no es día hábil')
            ->assertSuccessful();
        Notification::assertNothingSent();

        $this->artisan('projects:send-alerts', ['--date' => '2026-10-12', '--force' => true])->assertSuccessful();
        Notification::assertSentTo($this->member, DailyAlertsNotification::class);
    }

    public function test_deactivated_assignees_hand_their_alerts_to_the_project_owner(): void
    {
        $this->member->forceFill(['deactivated_at' => now()])->save();
        $this->task('en-ejecucion', '2026-09-18', $this->member);

        $this->artisan('projects:send-alerts')->assertSuccessful();

        Notification::assertNotSentTo($this->member, DailyAlertsNotification::class);
        Notification::assertSentTo($this->owner, DailyAlertsNotification::class);
    }

    public function test_digest_email_lists_the_items(): void
    {
        $notification = new DailyAlertsNotification([
            'tasks_overdue' => [['label' => 'Migrar datos', 'detail' => 'TED-1 · venció el 18 sep', 'url' => 'https://app.test/p/1']],
        ]);

        $mail = $notification->toMail($this->member);

        $this->assertSame('Tus pendientes de hoy', $mail->subject);
        $this->assertContains('**Tareas vencidas**', $mail->introLines);
        $this->assertContains('• [Migrar datos](https://app.test/p/1) — TED-1 · venció el 18 sep', $mail->introLines);
    }
}
