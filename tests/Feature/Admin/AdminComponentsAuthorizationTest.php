<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\ProjectCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesProjectUsers;
use Tests\TestCase;

/**
 * The administration components refuse unauthorised users by themselves,
 * without relying on the route middleware.
 */
class AdminComponentsAuthorizationTest extends TestCase
{
    use CreatesProjectUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedProjectsModule();
    }

    public function test_users_component_refuses_non_admins(): void
    {
        $leader = $this->userWithRole(Role::Leader);
        $victim = User::factory()->create();

        Livewire::actingAs($leader)->test('pages::admin.users')->assertForbidden();

        $this->assertTrue($victim->fresh()->isActive());
    }

    public function test_catalogs_component_refuses_people_without_permission(): void
    {
        Livewire::actingAs($this->userWithRole(Role::ProjectManager))->test('pages::admin.catalogs')->assertForbidden();

        $this->assertSame(11, ProjectCategory::count());
    }

    public function test_audit_component_refuses_people_without_permission(): void
    {
        Livewire::actingAs($this->userWithRole(Role::Member))->test('pages::admin.audit')->assertForbidden();
    }
}
