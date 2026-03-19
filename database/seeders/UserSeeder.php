<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            $tenant->makeCurrent();

            $users = [
                ['name' => "Owner {$tenant->name}", 'email' => "owner@$tenant->slug.com", 'role' => 'salon_owner'],
                ['name' => "Manager {$tenant->name}", 'email' => "manager@$tenant->slug.com", 'role' => 'manager'],
                ['name' => "Staff {$tenant->name}", 'email' => "staff@$tenant->slug.com", 'role' => 'staff'],
                ['name' => "Customer {$tenant->name}", 'email' => "customer@$tenant->slug.com", 'role' => 'customer'],
            ];

            foreach ($users as $userData) {
                $user = User::firstOrCreate(
                    ['email' => $userData['email']],
                    [
                        'name' => $userData['name'],
                        'password' => Hash::make('password'),
                        'tenant_id' => $tenant->id,
                    ]
                );
                $user->syncRoles([Role::findByName($userData['role'], 'api')]);
            }

            Tenant::forgetCurrent();
        }
    }
}
