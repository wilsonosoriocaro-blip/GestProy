<?php

namespace Tests\Feature\Notifications;

use App\Enums\Role;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\ProjectTask;
use App\Models\User;
use App\Notifications\Channels\TeamsWebhookChannel;
use App\Notifications\Projects\ProjectAtRiskNotification;
use App\Notifications\Projects\ProjectOwnerAssignedNotification;
use App\Notifications\Projects\TaskAssignedNotification;
use App\Services\Notifications\ProjectNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\CreatesProjectUsers;
use Tests\TestCase;

class ProjectNotificationsTest extends TestCase
{
    use CreatesProjectUsers, RefreshDatabase;

    private User $owner;

    private User $member;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedProjectsModule();
        Notification::fake();

        $this->owner = $this->userWithRole(Role::ProjectManager);
        $this->member = $this->userWithRole(Role::Member);
        $this->project = Project::factory()->create([
            'owner_id' => $this->owner->id,
            'status_id' => ProjectStatus::where('slug', 'en-ejecucion')->value('id'),
        ]);
        $this->project->members()->attach($this->member, ['role' => 'member']);
    }

    private function statusId(string $slug): int
    {
        return ProjectStatus::where('slug', $slug)->value('id');
    }

    public function test_assignee_is_notified_through_the_app_and_by_email(): void
    {
        Livewire::actingAs($this->owner)->test('projects.task-form', ['project' => $this->project])
            ->call('open')
            ->set('form.name', 'Pruebas integrales')
            ->set('form.assignee_id', (string) $this->member->id)
            ->set('form.due_date', '2026-12-01')
            ->call('save')
            ->assertHasNoErrors();

        Notification::assertSentTo($this->member, TaskAssignedNotification::class, function (TaskAssignedNotification $notification, array $channels) {
            $data = $notification->toArray($this->member);

            return $channels === ['database', 'mail']
                && str_contains($data['body'], 'Pruebas integrales')
                && str_contains($data['body'], $this->owner->name)
                && $data['url'] === route('projects.show', $this->project);
        });
        Notification::assertNotSentTo($this->owner, TaskAssignedNotification::class);
    }

    public function test_nobody_is_notified_about_their_own_assignment(): void
    {
        Livewire::actingAs($this->owner)->test('projects.task-form', ['project' => $this->project])
            ->call('open')
            ->set('form.name', 'Me la asigno')
            ->set('form.assignee_id', (string) $this->owner->id)
            ->call('save');

        Notification::assertNothingSent();
    }

    public function test_reassigning_a_task_notifies_the_new_assignee_only(): void
    {
        $task = ProjectTask::factory()->for($this->project)->create(['assignee_id' => $this->owner->id]);

        Livewire::actingAs($this->owner)->test('projects.task-form', ['project' => $this->project])
            ->call('open', $task->id)
            ->set('form.progress', 20)
            ->call('save');
        Notification::assertNothingSent();

        Livewire::actingAs($this->owner)->test('projects.task-form', ['project' => $this->project])
            ->call('open', $task->id)
            ->set('form.assignee_id', (string) $this->member->id)
            ->call('save');
        Notification::assertSentToTimes($this->member, TaskAssignedNotification::class, 1);
    }

    public function test_new_project_owner_is_notified(): void
    {
        $leader = $this->userWithRole(Role::Leader);
        $newOwner = $this->userWithRole(Role::ProjectManager);

        Livewire::actingAs($leader)->test('pages::projects.form', ['project' => $this->project])
            ->set('form.owner_id', $newOwner->id)
            ->call('save');

        Notification::assertSentTo($newOwner, ProjectOwnerAssignedNotification::class);
        Notification::assertNotSentTo($this->owner, ProjectOwnerAssignedNotification::class);
    }

    public function test_project_going_at_risk_warns_owner_and_leaders_once(): void
    {
        $leaderA = $this->userWithRole(Role::Leader);
        $leaderB = $this->userWithRole(Role::Leader);
        $retired = $this->userWithRole(Role::Leader);
        $retired->forceFill(['deactivated_at' => now()])->save();

        Livewire::actingAs($leaderA)->test('pages::projects.form', ['project' => $this->project])
            ->set('form.status_id', $this->statusId('en-riesgo'))
            ->call('save');

        Notification::assertSentTo($this->owner, ProjectAtRiskNotification::class);
        Notification::assertSentTo($leaderB, ProjectAtRiskNotification::class);
        Notification::assertNotSentTo($leaderA, ProjectAtRiskNotification::class);
        Notification::assertNotSentTo($retired, ProjectAtRiskNotification::class);
        Notification::assertNotSentTo($this->member, ProjectAtRiskNotification::class);

        // Editing a project that is already at risk does not warn again.
        Livewire::actingAs($leaderA)->test('pages::projects.form', ['project' => $this->project->refresh()])
            ->set('form.name', 'Otro nombre')
            ->call('save');

        Notification::assertSentToTimes($this->owner, ProjectAtRiskNotification::class, 1);
    }

    public function test_people_can_turn_email_off(): void
    {
        $this->member->forceFill(['email_notifications' => false])->save();

        $this->assertSame(['database'], (new TaskAssignedNotification(ProjectTask::factory()->for($this->project)->create()))->via($this->member));

        Livewire::actingAs($this->member)->test('pages::settings.notifications')
            ->set('emailNotifications', true)
            ->call('save');

        $this->assertTrue($this->member->fresh()->email_notifications);
    }

    public function test_deactivated_people_are_not_notified(): void
    {
        $this->member->forceFill(['deactivated_at' => now()])->save();
        $task = ProjectTask::factory()->for($this->project)->create(['assignee_id' => $this->member->id]);

        app(ProjectNotifier::class)->taskAssigned($task, $this->owner);

        Notification::assertNothingSent();
    }

    public function test_teams_webhook_receives_at_risk_alerts_when_configured(): void
    {
        config(['projects.notifications.teams_webhook_url' => 'https://example.webhook.office.com/hook']);

        app(ProjectNotifier::class)->projectAtRisk($this->project, $this->owner);

        Notification::assertSentOnDemand(ProjectAtRiskNotification::class, function ($notification, $channels, $notifiable) {
            return $notifiable->routes === ['teams' => 'https://example.webhook.office.com/hook'];
        });
    }

    public function test_teams_channel_posts_an_adaptive_card(): void
    {
        Http::fake();

        (new TeamsWebhookChannel)->send(
            Notification::route('teams', 'https://example.webhook.office.com/hook'),
            new ProjectAtRiskNotification($this->project, 'Laura'),
        );

        Http::assertSent(fn ($request) => $request->url() === 'https://example.webhook.office.com/hook'
            && $request['attachments'][0]['content']['type'] === 'AdaptiveCard'
            && str_contains($request['attachments'][0]['content']['body'][0]['text'], $this->project->code));
    }
}
