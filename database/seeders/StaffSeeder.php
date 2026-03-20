<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Staff;
use App\Models\StaffSchedule;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class StaffSeeder extends Seeder
{
    public function run(): void
    {
        $staffData = [
            ['name' => 'Sara Ahmed',   'phone' => '+966501001001', 'specialization' => 'Hair'],
            ['name' => 'Lina Hassan',  'phone' => '+966501001002', 'specialization' => 'Nails'],
            ['name' => 'Nora Khalid',  'phone' => '+966501001003', 'specialization' => 'Skin'],
            ['name' => 'Reem Saleh',   'phone' => '+966501001004', 'specialization' => 'Makeup'],
        ];

        // Mon–Sat working (aligns with ServiceAvailabilitySeeder); Sunday closed
        $schedule = [
            ['day_of_week' => 0, 'start_time' => '09:00', 'end_time' => '18:00', 'is_day_off' => true],  // Sun – closed
            ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '18:00', 'is_day_off' => false], // Mon
            ['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '18:00', 'is_day_off' => false], // Tue
            ['day_of_week' => 3, 'start_time' => '09:00', 'end_time' => '18:00', 'is_day_off' => false], // Wed
            ['day_of_week' => 4, 'start_time' => '09:00', 'end_time' => '18:00', 'is_day_off' => false], // Thu
            ['day_of_week' => 5, 'start_time' => '09:00', 'end_time' => '18:00', 'is_day_off' => false], // Fri
            ['day_of_week' => 6, 'start_time' => '10:00', 'end_time' => '16:00', 'is_day_off' => false], // Sat – shorter
        ];

        Tenant::all()->each(function (Tenant $tenant) use ($staffData, $schedule) {
            $tenant->makeCurrent();

            $branch = Branch::where('tenant_id', $tenant->id)->first();
            if (!$branch) {
                Tenant::forgetCurrent();
                return;
            }

            foreach ($staffData as $data) {
                $staff = Staff::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => $data['name']],
                    array_merge($data, [
                        'tenant_id' => $tenant->id,
                        'branch_id' => $branch->id,
                        'is_active' => true,
                    ])
                );

                // Always overwrite schedules so re-seeding fixes stale data
                $staff->schedules()->delete();
                $staff->schedules()->createMany(
                    collect($schedule)->map(fn ($s) => array_merge($s, ['tenant_id' => $tenant->id]))->toArray()
                );
            }

            Tenant::forgetCurrent();
        });
    }
}
