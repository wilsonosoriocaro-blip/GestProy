<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            ProjectCatalogSeeder::class,
        ]);

        // Public registration is disabled: this is the first account. Change its password after the first login.
        $admin = User::firstOrCreate(['email' => 'admin@example.com'], [
            'name' => 'Administrador',
            'password' => 'password',
            'email_verified_at' => now(),
        ]);

        $admin->assignRole(Role::Admin->value);
    }
}
