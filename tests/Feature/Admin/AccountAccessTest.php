<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesProjectUsers;
use Tests\TestCase;

class AccountAccessTest extends TestCase
{
    use CreatesProjectUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedProjectsModule();
    }

    public function test_deactivated_users_cannot_sign_in(): void
    {
        $user = User::factory()->create(['deactivated_at' => now()]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'Tu cuenta está desactivada. Comunícate con el administrador.']);

        $this->assertGuest();
    }

    public function test_active_users_still_sign_in(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_an_open_session_ends_when_the_user_is_deactivated(): void
    {
        $user = $this->userWithRole(Role::Leader);
        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $user->forceFill(['deactivated_at' => now()])->save();

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_project_owners_cannot_delete_their_own_account(): void
    {
        $owner = $this->userWithRole(Role::ProjectManager);
        Project::factory()->create(['owner_id' => $owner->id]);

        Livewire::actingAs($owner)->test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasErrors(['password']);

        $this->assertNotNull($owner->fresh());
    }

    public function test_users_without_projects_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasNoErrors();

        $this->assertNull($user->fresh());
    }
}
