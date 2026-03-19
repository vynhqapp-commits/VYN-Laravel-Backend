<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class AppointmentSeeder extends Seeder
{
    /**
     * Weighted source distribution matching real-world booking patterns.
     * 50% dashboard, 30% walk-in, 15% public, 5% online.
     */
    private const SOURCES = [
        'dashboard', 'dashboard', 'dashboard', 'dashboard', 'dashboard',
        'walk_in',   'walk_in',   'walk_in',
        'public',    'public',
        'online',
    ];

    public function run(): void
    {
        $historicStatuses = ['pending', 'confirmed', 'completed', 'completed', 'completed', 'cancelled', 'no_show'];

        Tenant::all()->each(function (Tenant $tenant) use ($historicStatuses) {
            $tenant->makeCurrent();

            $branch    = Branch::where('tenant_id', $tenant->id)->first();
            $customers = Customer::where('tenant_id', $tenant->id)->get();
            $staffList = Staff::where('tenant_id', $tenant->id)->get();
            $services  = Service::where('tenant_id', $tenant->id)->get();

            if (! $branch || $customers->isEmpty() || $staffList->isEmpty() || $services->isEmpty()) {
                Tenant::forgetCurrent();
                return;
            }

            // ── 20 historical appointments spread over the last 30 days ──────────────
            for ($i = 0; $i < 20; $i++) {
                $startsAt = Carbon::now()
                    ->subDays(rand(1, 30))
                    ->setHour(rand(9, 17))
                    ->setMinute(0)
                    ->setSecond(0);
                $service = $services->random();
                $endsAt  = $startsAt->copy()->addMinutes((int) $service->duration_minutes);

                $sourceKey  = self::SOURCES[array_rand(self::SOURCES)];
                $statusKey  = $historicStatuses[array_rand($historicStatuses)];

                $appointment = Appointment::create([
                    'tenant_id'   => $tenant->id,
                    'branch_id'   => $branch->id,
                    'customer_id' => $customers->random()->id,
                    'staff_id'    => $staffList->random()->id,
                    'starts_at'   => $startsAt,
                    'ends_at'     => $endsAt,
                    'status'      => $statusKey,
                    'source'      => $sourceKey,
                    'notes'       => rand(0, 1) ? 'Customer prefers quiet environment' : null,
                ]);

                AppointmentService::create([
                    'appointment_id'   => $appointment->id,
                    'service_id'       => $service->id,
                    'price'            => $service->price,
                    'duration_minutes' => $service->duration_minutes,
                ]);
            }

            // ── 4 future scheduled appointments (next 1–7 days) ───────────────────────
            // These allow the public availability endpoint to return real data immediately.
            $futureOffsets = [1, 2, 4, 7];
            foreach ($futureOffsets as $daysAhead) {
                // Use a Mon–Fri working day window (09:00–16:00); pick a random hour
                $startsAt = Carbon::now()
                    ->addDays($daysAhead)
                    ->setHour(rand(9, 15))
                    ->setMinute(0)
                    ->setSecond(0);

                // Skip if the day falls on Sunday (no availability seeded for Sun)
                if ($startsAt->dayOfWeek === Carbon::SUNDAY) {
                    $startsAt->addDay();
                }

                $service = $services->random();
                $endsAt  = $startsAt->copy()->addMinutes((int) $service->duration_minutes);

                $sourceKey = self::SOURCES[array_rand(self::SOURCES)];

                $appointment = Appointment::create([
                    'tenant_id'   => $tenant->id,
                    'branch_id'   => $branch->id,
                    'customer_id' => $customers->random()->id,
                    'staff_id'    => $staffList->random()->id,
                    'starts_at'   => $startsAt,
                    'ends_at'     => $endsAt,
                    'status'      => 'scheduled',
                    'source'      => $sourceKey,
                    'notes'       => null,
                ]);

                AppointmentService::create([
                    'appointment_id'   => $appointment->id,
                    'service_id'       => $service->id,
                    'price'            => $service->price,
                    'duration_minutes' => $service->duration_minutes,
                ]);
            }

            Tenant::forgetCurrent();
        });
    }
}
