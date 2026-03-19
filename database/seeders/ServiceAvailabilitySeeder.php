<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Service;
use App\Models\ServiceBranchAvailability;
use App\Models\ServiceBranchAvailabilityOverride;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ServiceAvailabilitySeeder extends Seeder
{
    /**
     * Weekly windows for every service × branch.
     * day_of_week: 0=Sun, 1=Mon … 6=Sat
     */
    private const WEEKLY_WINDOWS = [
        ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '18:00'], // Mon
        ['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '18:00'], // Tue
        ['day_of_week' => 3, 'start_time' => '09:00', 'end_time' => '18:00'], // Wed
        ['day_of_week' => 4, 'start_time' => '09:00', 'end_time' => '18:00'], // Thu
        ['day_of_week' => 5, 'start_time' => '09:00', 'end_time' => '18:00'], // Fri
        ['day_of_week' => 6, 'start_time' => '10:00', 'end_time' => '16:00'], // Sat – shorter hours
    ];

    public function run(): void
    {
        Tenant::all()->each(function (Tenant $tenant) {
            $tenant->makeCurrent();

            $branches = Branch::where('tenant_id', $tenant->id)->where('is_active', true)->get();
            $services = Service::where('tenant_id', $tenant->id)->where('is_active', true)->get();

            if ($branches->isEmpty() || $services->isEmpty()) {
                Tenant::forgetCurrent();
                return;
            }

            // Next Sunday – used as the closed-day override to demonstrate the feature
            $nextSunday = Carbon::now()->next(Carbon::SUNDAY)->toDateString();

            foreach ($services as $service) {
                foreach ($branches as $branch) {
                    // --- Weekly recurring windows ---
                    foreach (self::WEEKLY_WINDOWS as $window) {
                        ServiceBranchAvailability::firstOrCreate(
                            [
                                'tenant_id'   => $tenant->id,
                                'service_id'  => $service->id,
                                'branch_id'   => $branch->id,
                                'day_of_week' => $window['day_of_week'],
                            ],
                            [
                                'start_time'   => $window['start_time'],
                                'end_time'     => $window['end_time'],
                                'slot_minutes' => null, // use service.duration_minutes
                                'is_active'    => true,
                            ]
                        );
                    }

                    // --- Closed-day override (next Sunday) ---
                    ServiceBranchAvailabilityOverride::firstOrCreate(
                        [
                            'tenant_id'  => $tenant->id,
                            'service_id' => $service->id,
                            'branch_id'  => $branch->id,
                            'date'       => $nextSunday,
                        ],
                        [
                            'is_closed'    => true,
                            'start_time'   => null,
                            'end_time'     => null,
                            'slot_minutes' => null,
                        ]
                    );

                    // --- Special-hours override: next Saturday shortened to 10:00–14:00 ---
                    $nextSaturday = Carbon::now()->next(Carbon::SATURDAY)->toDateString();
                    if ($nextSaturday !== $nextSunday) {
                        ServiceBranchAvailabilityOverride::firstOrCreate(
                            [
                                'tenant_id'  => $tenant->id,
                                'service_id' => $service->id,
                                'branch_id'  => $branch->id,
                                'date'       => $nextSaturday,
                            ],
                            [
                                'is_closed'    => false,
                                'start_time'   => '10:00',
                                'end_time'     => '14:00',
                                'slot_minutes' => null,
                            ]
                        );
                    }
                }
            }

            Tenant::forgetCurrent();
        });
    }
}
