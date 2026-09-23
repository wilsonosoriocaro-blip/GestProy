<?php

namespace Tests\Feature\Notifications;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use App\Notifications\Projects\TaskAssignedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesProjectUsers;
use Tests\TestCase;

class NotificationBellTest extends TestCase
{
    use CreatesProjectUsers, RefreshDatabase;

    public function test_bell_lists_unread_notifications_and_marks_them_read(): void
    {
        $this->seedProjectsModule();
        $user = User::factory()->create();
        $task = ProjectTask::factory()->for(Project::factory())->create(['name' => 'Configurar QA']);
        $user->notifyNow(new TaskAssignedNotification($task, 'Laura'), ['database']);
        $user->notifyNow(new TaskAssignedNotification($task, 'Laura'), ['database']);

        $bell = Livewire::actingAs($user)->test('notifications.bell')
            ->assertSet('showAll', false)
            ->assertSee('Configurar QA');

        $this->assertSame(2, $bell->instance()->unread);

        $first = $user->unreadNotifications()->first();
        $bell->call('openItem', $first->id)->assertRedirect(route('projects.show', $task->project_id));
        $this->assertSame(1, $user->unreadNotifications()->count());

        $bell->call('markAllAsRead');
        $this->assertSame(0, $user->unreadNotifications()->count());
        $bell->assertSee('Estás al día');
    }

    public function test_bell_is_in_the_menu(): void
    {
        $this->seedProjectsModule();

        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk()->assertSee('Notificaciones');
    }

    public function test_people_only_open_their_own_notifications(): void
    {
        $this->seedProjectsModule();
        $task = ProjectTask::factory()->create();
        $owner = User::factory()->create();
        $owner->notifyNow(new TaskAssignedNotification($task), ['database']);

        Livewire::actingAs(User::factory()->create())->test('notifications.bell')
            ->call('openItem', $owner->notifications()->first()->id)
            ->assertNotFound();
    }
}
