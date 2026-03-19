<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class AppointmentSeeder extends Seeder
{
    public function run(): void
    {
        $statuses = ['pending', 'confirmed', 'completed', 'cancelled', 'no_show'];

        Tenant::all()->each(function (Tenant $tenant) use ($statuses) {
            $tenant->makeCurrent();

            $branch    = Branch::where('tenant_id', $tenant->id)->first();
            $customers = Customer::where('tenant_id', $tenant->id)->get();
            $staffList = Staff::where('tenant_id', $tenant->id)->get();
            $services  = Service::where('tenant_id', $tenant->id)->get();

            if ($customers->isEmpty() || $staffList->isEmpty() || $services->isEmpty()) {
                return;
            }

            // Create 20 appointments spread over last 30 days
            for ($i = 0; $i < 20; $i++) {
                $startsAt  = now()->subDays(rand(0, 30))->setHour(rand(9, 17))->setMinute(0)->setSecond(0);
                $service   = $services->random();
                $endsAt    = $startsAt->copy()->addMinutes($service->duration_minutes);

                $appointment = Appointment::create([
                    'tenant_id'   => $tenant->id,
                    'branch_id'   => $branch->id,
                    'customer_id' => $customers->random()->id,
                    'staff_id'    => $staffList->random()->id,
                    'starts_at'   => $startsAt,
                    'ends_at'     => $endsAt,
                    'status'      => $statuses[array_rand($statuses)],
                    'notes'       => rand(0, 1) ? 'Customer prefers quiet environment' : null,
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
