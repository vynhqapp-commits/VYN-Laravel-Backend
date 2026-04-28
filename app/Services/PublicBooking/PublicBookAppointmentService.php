<?php

namespace App\Services\PublicBooking;

use App\Mail\BookingConfirmationMail;
use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Service;
use App\Models\StaffSchedule;
use App\Models\TimeBlock;
use App\Services\Notifications\BookingNotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class PublicBookAppointmentService
{
    public function __construct(
        private readonly PublicBookingWindowService $windows,
        private readonly BookingNotificationService $bookingNotifications,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated book payload
     *
     * @throws \DomainException
     */
    public function book(Request $request, array $data): Appointment
    {
        $service = Service::withoutGlobalScopes()->findOrFail($data['service_id']);
        $startAt = Carbon::parse($data['start_at']);
        $endAt = $startAt->copy()->addMinutes($service->duration_minutes);
        $dayOfWeek = $startAt->dayOfWeek;

        $tenantId = (int) $data['tenant_id'];
        $branch = Branch::withoutGlobalScopes()->findOrFail($data['branch_id']);
        if ((int) $branch->tenant_id !== $tenantId) {
            throw new \DomainException('Invalid branch for tenant');
        }
        if ((int) $service->tenant_id !== $tenantId) {
            throw new \DomainException('Invalid service for tenant');
        }

        $windowList = $this->windows->resolveServiceWindows($tenantId, (int) $service->id, (int) $branch->id, $startAt->copy());
        if ($windowList->isEmpty() || ! $this->windows->isWithinServiceWindow($startAt, $endAt, $windowList)) {
            throw new \DomainException('Selected slot is not in service availability window.');
        }

        $staffId = $data['staff_id'] ?? null;

        if (! $staffId) {
            $staffId = StaffSchedule::withoutGlobalScopes()
                ->whereHas('staff', fn ($q) => $q->withoutGlobalScopes()
                    ->where('branch_id', $data['branch_id'])
                    ->where('tenant_id', $data['tenant_id'])
                    ->where('is_active', true))
                ->where('day_of_week', $dayOfWeek)
                ->where('is_day_off', false)
                ->whereDoesntHave('staff.appointments', fn ($q) => $q->withoutGlobalScopes()
                    ->where('branch_id', $data['branch_id'])
                    ->whereIn('status', ['scheduled', 'confirmed'])
                    ->where('starts_at', '<', $endAt)
                    ->where('ends_at', '>', $startAt))
                ->value('staff_id');
        }

        if (! $staffId) {
            throw new \DomainException('No staff available for the selected time slot.');
        }

        try {
            $appointment = DB::transaction(function () use ($request, $data, $tenantId, $service, $staffId, $startAt, $endAt) {
                $conflictExists = Appointment::withoutGlobalScopes()
                    ->lockForUpdate()
                    ->where('tenant_id', $tenantId)
                    ->where('branch_id', $data['branch_id'])
                    ->where('staff_id', $staffId)
                    ->whereIn('status', ['scheduled', 'confirmed'])
                    ->where('starts_at', '<', $endAt)
                    ->where('ends_at', '>', $startAt)
                    ->exists();

                if ($conflictExists) {
                    throw new \DomainException('Selected slot is no longer available.');
                }

                $blocked = TimeBlock::withoutGlobalScopes()
                    ->lockForUpdate()
                    ->where('tenant_id', $tenantId)
                    ->where('branch_id', $data['branch_id'])
                    ->where(function ($q) use ($staffId) {
                        $q->whereNull('staff_id')->orWhere('staff_id', $staffId);
                    })
                    ->where('starts_at', '<', $endAt)
                    ->where('ends_at', '>', $startAt)
                    ->exists();

                if ($blocked) {
                    throw new \DomainException('Selected slot is blocked.');
                }

                $customer = Customer::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where(function ($q) use ($data) {
                        if (! empty($data['client_email'])) {
                            $q->where('email', $data['client_email']);
                        } else {
                            $q->where('phone', $data['client_phone'] ?? '')
                                ->where('name', $data['client_name']);
                        }
                    })
                    ->first();

                if (! $customer) {
                    $customer = Customer::withoutGlobalScopes()->create([
                        'tenant_id' => $tenantId,
                        'name' => $data['client_name'],
                        'phone' => $data['client_phone'] ?? null,
                        'email' => $data['client_email'] ?? null,
                    ]);
                }

                if ($request->bearerToken() && ! $customer->user_id) {
                    try {
                        $authUser = auth('api')->user();
                        if ($authUser && $authUser->hasRole('customer')) {
                            $customer->update(['user_id' => $authUser->id]);
                        }
                    } catch (\Throwable) {
                    }
                }

                $appointment = Appointment::withoutGlobalScopes()->create([
                    'tenant_id' => $data['tenant_id'],
                    'branch_id' => $data['branch_id'],
                    'customer_id' => $customer->id,
                    'staff_id' => $staffId,
                    'starts_at' => $startAt,
                    'ends_at' => $endAt,
                    'status' => 'scheduled',
                    'source' => 'public',
                    'notes' => 'Booked via public booking',
                ]);

                AppointmentService::create([
                    'appointment_id' => $appointment->id,
                    'service_id' => $service->id,
                    'price' => $service->price,
                    'duration_minutes' => $service->duration_minutes,
                ]);

                return $appointment;
            });
        } catch (\DomainException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new \RuntimeException('Booking failed. Please try again.');
        }

        $appointment->load(['branch', 'staff', 'customer', 'services.service']);

        $customerEmail = $appointment->customer?->email;
        if (! empty($customerEmail)) {
            try {
                $locale = $request->input('locale', 'en');
                Mail::to($customerEmail)->send(new BookingConfirmationMail($appointment, $locale));
            } catch (\Throwable) {
            }
        }

        try {
            $appointment->loadMissing('customer.user');
            $this->bookingNotifications->sendConfirmation($appointment);
        } catch (\Throwable) {
        }

        return $appointment;
    }
}
