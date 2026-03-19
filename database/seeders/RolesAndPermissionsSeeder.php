<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $allPermissions = [
            // Platform (super admin)
            'tenants.view', 'tenants.create', 'tenants.update', 'tenants.delete', 'tenants.suspend',
            'platform.reports', 'platform.settings',
            // Booking
            'booking.view', 'booking.create', 'booking.update', 'booking.cancel',
            // POS
            'pos.view', 'pos.create', 'pos.refund',
            // Inventory
            'inventory.view', 'inventory.manage',
            // Staff
            'staff.view', 'staff.manage',
            // Reports
            'reports.view', 'reports.export',
            // ERP
            'erp.view', 'erp.manage',
            // Settings
            'settings.manage',
        ];

        foreach ($allPermissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
        }

        $roles = [
            'super_admin'  => $allPermissions,
            'salon_owner'  => ['booking.view', 'booking.create', 'booking.update', 'booking.cancel', 'pos.view', 'pos.create', 'pos.refund', 'inventory.view', 'inventory.manage', 'staff.view', 'staff.manage', 'reports.view', 'reports.export', 'erp.view', 'erp.manage', 'settings.manage'],
            'manager'      => ['booking.view', 'booking.create', 'booking.update', 'booking.cancel', 'pos.view', 'pos.create', 'pos.refund', 'inventory.view', 'inventory.manage', 'staff.view', 'reports.view', 'reports.export', 'erp.view'],
            'staff'        => ['booking.view', 'booking.create', 'booking.update', 'pos.view', 'pos.create'],
            'customer'     => ['booking.view', 'booking.create', 'booking.cancel'],
        ];

        foreach ($roles as $roleName => $rolePermissions) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'api']);
            $role->syncPermissions($rolePermissions);
        }

        // Default super admin user (no tenant)
        $superAdmin = User::firstOrCreate(
            ['email' => 'admin@platform.com'],
            [
                'name'      => 'Platform Admin',
                'password'  => Hash::make('password'),
                'tenant_id' => null,
            ]
        );
        $superAdmin->assignRole(Role::findByName('super_admin', 'api'));

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
