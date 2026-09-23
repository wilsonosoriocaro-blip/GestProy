<?php

namespace Tests\Concerns;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\ProjectCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

trait CreatesProjectUsers
{
    protected function seedProjectsModule(): void
    {
        $this->seed([RolesAndPermissionsSeeder::class, ProjectCatalogSeeder::class]);
    }

    protected function userWithRole(Role $role): User
    {
        return User::factory()->create()->assignRole($role->value);
    }
}
