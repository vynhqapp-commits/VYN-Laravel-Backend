<?php

namespace Database\Seeders;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'Hair' => [
                ['name' => 'Haircut',        'duration_minutes' => 30,  'price' => 80,  'cost' => 20],
                ['name' => 'Hair Coloring',  'duration_minutes' => 90,  'price' => 250, 'cost' => 80],
                ['name' => 'Blow Dry',       'duration_minutes' => 45,  'price' => 100, 'cost' => 25],
                ['name' => 'Hair Treatment', 'duration_minutes' => 60,  'price' => 180, 'cost' => 50],
            ],
            'Nails' => [
                ['name' => 'Manicure',       'duration_minutes' => 45,  'price' => 70,  'cost' => 15],
                ['name' => 'Pedicure',       'duration_minutes' => 60,  'price' => 90,  'cost' => 20],
                ['name' => 'Gel Nails',      'duration_minutes' => 75,  'price' => 150, 'cost' => 40],
            ],
            'Skin' => [
                ['name' => 'Facial',         'duration_minutes' => 60,  'price' => 200, 'cost' => 60],
                ['name' => 'Deep Cleansing', 'duration_minutes' => 75,  'price' => 250, 'cost' => 70],
            ],
            'Makeup' => [
                ['name' => 'Full Makeup',    'duration_minutes' => 90,  'price' => 300, 'cost' => 80],
                ['name' => 'Eye Makeup',     'duration_minutes' => 45,  'price' => 150, 'cost' => 40],
            ],
        ];

        Tenant::all()->each(function (Tenant $tenant) use ($categories) {
            $tenant->makeCurrent();

            foreach ($categories as $categoryName => $services) {
                $category = ServiceCategory::firstOrCreate([
                    'tenant_id' => $tenant->id,
                    'name'      => $categoryName,
                ]);

                foreach ($services as $service) {
                    Service::firstOrCreate(
                        ['tenant_id' => $tenant->id, 'name' => $service['name']],
                        array_merge($service, [
                            'tenant_id'           => $tenant->id,
                            'service_category_id' => $category->id,
                            'is_active'           => true,
                        ])
                    );
                }
            }

            Tenant::forgetCurrent();
        });
    }
}
