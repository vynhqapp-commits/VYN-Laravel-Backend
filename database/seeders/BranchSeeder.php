<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            $tenant->makeCurrent();

            $branches = [
                [
                    'tenant_id' => $tenant->id,
                    'name'      => $tenant->name . ' - Main Branch',
                    'phone'     => '+966501110001',
                    'address'   => 'Main Street, Downtown',
                    'timezone'  => $tenant->timezone,
                    'is_active' => true,
                ],
                [
                    'tenant_id' => $tenant->id,
                    'name'      => $tenant->name . ' - North Branch',
                    'phone'     => '+966501110002',
                    'address'   => 'North District, Mall',
                    'timezone'  => $tenant->timezone,
                    'is_active' => true,
                ],
            ];

            foreach ($branches as $branch) {
                Branch::firstOrCreate(
                    ['tenant_id' => $branch['tenant_id'], 'name' => $branch['name']],
                    $branch
                );
            }

            Tenant::forgetCurrent();
        }
    }
}
