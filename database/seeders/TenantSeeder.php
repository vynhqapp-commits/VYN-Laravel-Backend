<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = [
            [
                'name'                => 'Glamour Salon',
                'slug'                => 'glamour-salon',
                'domain'              => 'glamour.localhost',
                'plan'                => 'pro',
                'subscription_status' => 'active',
                'timezone'            => 'Asia/Riyadh',
                'currency'            => 'SAR',
                'phone'               => '+966501234567',
                'address'             => 'Riyadh, Saudi Arabia',
            ],
            [
                'name'                => 'Elite Beauty',
                'slug'                => 'elite-beauty',
                'domain'              => 'elite.localhost',
                'plan'                => 'basic',
                'subscription_status' => 'active',
                'timezone'            => 'Asia/Dubai',
                'currency'            => 'AED',
                'phone'               => '+971501234567',
                'address'             => 'Dubai, UAE',
            ],
        ];

        foreach ($tenants as $tenant) {
            Tenant::firstOrCreate(['slug' => $tenant['slug']], $tenant);
        }
    }
}
