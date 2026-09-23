<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\CreatesProjectUsers;
use Tests\TestCase;

class UserAdministrationTest extends TestCase
{
    use CreatesProjectUsers, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedProjectsModule();
        $this->admin = $this->userWithRole(Role::Admin);
    }

    private function page(?User $user = null): Testable
    {
        return Livewire::actingAs($user ?? $this->admin)->test('pages::admin.users');
    }

    public function test_only_admins_manage_users(): void
    {
        $this->actingAs($this->admin)->get(route('admin.users'))->assertOk();
        $this->actingAs($this->userWithRole(Role::Leader))->get(route('admin.users'))->assertForbidden();
        $this->actingAs($this->userWithRole(Role::Member))->get(route('admin.users'))->assertForbidden();
    }

    public function test_admin_creates_a_user_who_sets_their_own_password(): void
    {
        Notification::fake();

        $this->page()
            ->call('create')
            ->set('form.name', 'Diana Salazar')
            ->set('form.email', 'Diana@Empresa.co')
            ->set('form.role', Role::ProjectManager->value)
            ->call('save')
            ->assertHasNoErrors();

        $user = User::where('email', 'diana@empresa.co')->sole();
        $this->assertTrue($user->hasRole(Role::ProjectManager->value));
        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertSame('user.created', AuditLog::sole()->action);
        $this->assertSame($this->admin->id, AuditLog::sole()->user_id);
    }

    public function test_validation(): void
    {
        $this->page()
            ->call('create')
            ->set('form.name', '')
            ->set('form.email', $this->admin->email)
            ->set('form.role', 'superuser')
            ->call('save')
            ->assertHasErrors(['form.name', 'form.email' => 'unique', 'form.role']);
    }

    public function test_role_changes_are_audited(): void
    {
        $user = $this->userWithRole(Role::Member);

        $this->page()
            ->call('edit', $user->id)
            ->assertSet('form.role', Role::Member->value)
            ->set('form.role', Role::Leader->value)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue($user->fresh()->hasRole(Role::Leader->value));
        $log = AuditLog::where('action', 'user.role_changed')->sole();
        $this->assertSame(['role' => 'member'], $log->old_values);
        $this->assertSame(['role' => 'leader'], $log->new_values);
    }

    public function test_admins_cannot_lock_themselves_out(): void
    {
        $this->page()
            ->call('edit', $this->admin->id)
            ->set('form.role', Role::Viewer->value)
            ->call('save')
            ->assertHasErrors(['form.role']);

        $this->page()->call('toggleActive', $this->admin->id)->assertHasErrors(['user']);

        $this->assertTrue($this->admin->fresh()->hasRole(Role::Admin->value));
        $this->assertTrue($this->admin->fresh()->isActive());
    }

    public function test_deactivation_keeps_history_and_removes_the_user_from_pick_lists(): void
    {
        $owner = $this->userWithRole(Role::ProjectManager);
        $worker = $this->userWithRole(Role::Member);
        $project = Project::factory()->create(['owner_id' => $owner->id]);
        $project->members()->attach($worker, ['role' => 'member']);
        $task = ProjectTask::factory()->for($project)->create(['assignee_id' => $worker->id]);

        $this->page()->call('toggleActive', $worker->id)->assertHasNoErrors();

        $this->assertFalse($worker->fresh()->isActive());
        $this->assertSame('user.deactivated', AuditLog::sole()->action);
        $this->assertSame($worker->id, $task->fresh()->assignee_id);

        // A new task cannot be assigned to the deactivated person…
        Livewire::actingAs($owner)->test('projects.task-form', ['project' => $project])
            ->call('open')
            ->set('form.name', 'Nueva')
            ->set('form.assignee_id', (string) $worker->id)
            ->call('save')
            ->assertHasErrors(['form.assignee_id']);

        // …but the task that already belongs to them can still be edited.
        Livewire::actingAs($owner)->test('projects.task-form', ['project' => $project])
            ->call('open', $task->id)
            ->set('form.progress', 50)
            ->call('save')
            ->assertHasNoErrors();

        $this->page()->set('status', 'inactive')->assertSee($worker->email);
        $this->page()->call('toggleActive', $worker->id);
        $this->assertTrue($worker->fresh()->isActive());
    }
}
