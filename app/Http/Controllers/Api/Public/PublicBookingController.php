<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicAppointmentResource;
use App\Http\Resources\PublicBranchResource;
use App\Http\Resources\PublicSalonResource;
use App\Http\Resources\PublicServiceResource;
use App\Mail\BookingConfirmationMail;
use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\ServiceBranchAvailability;
use App\Models\ServiceBranchAvailabilityOverride;
use App\Models\Service;
use App\Models\StaffSchedule;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class PublicBookingController extends Controller
{
    /**
     * GET /api/public/salons?search=&page=&per_page=
     * Paginated + searchable list of active salons.
     */
    public function salons(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $perPage = min((int) $request->query('per_page', 12), 50);
        $page = max((int) $request->query('page', 1), 1);

        $query = Tenant::withCount([
            'branches as branch_count' => fn($q) => $q->where('is_active', true),
        ])
            ->where('subscription_status', '!=', 'suspended')
            ->orderBy('name');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");
            });
        }

        $result = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => PublicSalonResource::collection($result)->resolve(),
            'meta' => [
                'current_page' => $result->currentPage(),
                'per_page' => $result->perPage(),
                'total' => $result->total(),
                'last_page' => $result->lastPage(),
                'from' => $result->firstItem(),
                'to' => $result->lastItem(),
            ],
            'links' => [
                'first' => $result->url(1),
                'last' => $result->url($result->lastPage()),
                'prev' => $result->previousPageUrl(),
                'next' => $result->nextPageUrl(),
            ],
        ]);
    }

    /**
     * GET /api/public/salons/{slug}
     * Salon profile with branches + services.
     */
    public function salon(string $slug): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)
            ->where('subscription_status', '!=', 'suspended')
            ->first();

        $data = null;
        if ($tenant) {
            $branches = Branch::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->get();

            $services = Service::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->with('category')
                ->orderBy('name')
                ->get();

            $data = compact('tenant', 'branches', 'services');
        }

        if (!$data) {
            return $this->notFound('Salon not found');
        }

        return $this->success([
            'salon' => (new PublicSalonResource($data['tenant']))->resolve(),
            'branches' => PublicBranchResource::collection($data['branches'])->resolve(),
            'services' => PublicServiceResource::collection($data['services'])->resolve(),
        ]);
    }

    /**
     * GET /api/public/availability?branch_id=&service_id=&date=YYYY-MM-DD
     * Available slots.
     */
    public function availability(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'branch_id' => 'required|exists:branches,id',
                'service_id' => 'required|exists:services,id',
                'date' => 'required|date_format:Y-m-d|after_or_equal:today',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $date = Carbon::parse($data['date']);
        $dayOfWeek = $date->dayOfWeek;
        $service = Service::withoutGlobalScopes()->findOrFail($data['service_id']);
        $duration = (int) $service->duration_minutes;

        $branchWindows = $this->resolveServiceWindows(
            (int) $service->tenant_id,
            (int) $service->id,
            (int) $data['branch_id'],
            $date
        );



        if ($branchWindows->isEmpty()) {
            return $this->success(['slots' => []]);
        }

        // Fetch staff schedules including their actual working hours for the day
        $staffSchedules = StaffSchedule::withoutGlobalScopes()
            ->whereHas('staff', fn($q) => $q->withoutGlobalScopes()
                ->where('tenant_id', $service->tenant_id)
                ->where('branch_id', $data['branch_id'])
                ->where('is_active', true))
            ->where('day_of_week', $dayOfWeek)
            ->where('is_day_off', false)
            ->get(['staff_id', 'start_time', 'end_time'])
            ->keyBy('staff_id');

        if ($staffSchedules->isEmpty()) {
            return $this->success(['slots' => []]);
        }

        $booked = Appointment::withoutGlobalScopes()
            ->where('tenant_id', $service->tenant_id)
            ->where('branch_id', $data['branch_id'])
            ->whereDate('starts_at', $date->toDateString())
            ->whereIn('status', ['scheduled', 'confirmed'])
            ->get(['staff_id', 'starts_at', 'ends_at']);

        $freeSlots = collect();

        foreach ($staffSchedules as $staffId => $sched) {
            foreach ($branchWindows as $window) {
                // Service availability window bounds
                $svcStart = Carbon::parse($date->toDateString() . ' ' . $window['start_time']);
                $svcEnd   = Carbon::parse($date->toDateString() . ' ' . $window['end_time']);

                // Staff's own shift bounds
                $shiftStart = Carbon::parse($date->toDateString() . ' ' . $sched->start_time);
                $shiftEnd   = Carbon::parse($date->toDateString() . ' ' . $sched->end_time);

                // Effective window = intersection of service window and staff shift
                $start = $svcStart->max($shiftStart);
                $end   = $svcEnd->min($shiftEnd);

                // No overlap between service window and staff shift — skip
                if ($start->gte($end)) {
                    continue;
                }

                $stepMinutes = (int) ($window['slot_minutes'] ?? 30);
                $staffBooked = $booked->where('staff_id', $staffId);
                $cursor      = $start->copy();

                // Only generate slots that finish before or at the effective end (duration buffer)
                while ($cursor->copy()->addMinutes($duration)->lte($end)) {
                    $slotEnd  = $cursor->copy()->addMinutes($duration);
                    $conflict = $staffBooked->first(function ($appt) use ($cursor, $slotEnd) {
                        $aStart = Carbon::parse($appt->starts_at);
                        $aEnd   = Carbon::parse($appt->ends_at);
                        return $cursor->lt($aEnd) && $slotEnd->gt($aStart);
                    });

                    if (!$conflict) {
                        $key = $cursor->format('Y-m-d\TH:i:s');
                        if (!$freeSlots->has($key)) {
                            $freeSlots->put($key, [
                                // No UTC marker: times are naive salon-local times.
                                // The frontend must NOT apply a timezone offset when displaying.
                                'start'    => $cursor->format('Y-m-d\TH:i:s'),
                                'end'      => $slotEnd->format('Y-m-d\TH:i:s'),
                                'staff_id' => $staffId,
                            ]);
                        }
                    }

                    $cursor->addMinutes(max(1, $stepMinutes));
                }
            }
        }

        $slots = $freeSlots->values()->sortBy('start')->values()->all();

        return $this->success(['slots' => $slots]);
    }

    /**
     * POST /api/public/book
     * Create a guest appointment.
     */
    public function book(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'tenant_id' => 'required|exists:tenants,id',
                'branch_id' => 'required|exists:branches,id',
                'service_id' => 'required|exists:services,id',
                'staff_id' => 'nullable|exists:staff,id',
                'start_at' => 'required|date|after:now',
                'client_name' => 'required|string|max:120',
                'client_phone' => 'nullable|string|max:30',
                'client_email' => 'nullable|email|max:120',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $service = Service::withoutGlobalScopes()->findOrFail($data['service_id']);
        $startAt = Carbon::parse($data['start_at']);
        $endAt = $startAt->copy()->addMinutes($service->duration_minutes);
        $dayOfWeek = $startAt->dayOfWeek; // 0=Sun, 1=Mon … 6=Sat

        $tenantId = (int) $data['tenant_id'];
        $branch = Branch::withoutGlobalScopes()->findOrFail($data['branch_id']);
        if ((int) $branch->tenant_id !== $tenantId) {
            return $this->error('Invalid branch for tenant', 422);
        }
        if ((int) $service->tenant_id !== $tenantId) {
            return $this->error('Invalid service for tenant', 422);
        }

        $windows = $this->resolveServiceWindows($tenantId, (int) $service->id, (int) $branch->id, $startAt->copy());
        if ($windows->isEmpty() || !$this->isWithinServiceWindow($startAt, $endAt, $windows)) {
            return $this->error('Selected slot is not in service availability window.', 422);
        }

        $staffId = $data['staff_id'] ?? null;

        if (!$staffId) {
            $staffId = StaffSchedule::withoutGlobalScopes()
                ->whereHas('staff', fn($q) => $q->withoutGlobalScopes()
                    ->where('branch_id', $data['branch_id'])
                    ->where('tenant_id', $data['tenant_id'])
                    ->where('is_active', true))
                ->where('day_of_week', $dayOfWeek)
                ->where('is_day_off', false)
                ->whereDoesntHave('staff.appointments', fn($q) => $q->withoutGlobalScopes()
                    ->where('branch_id', $data['branch_id'])
                    ->whereIn('status', ['scheduled', 'confirmed'])
                    ->where('starts_at', '<', $endAt)
                    ->where('ends_at', '>', $startAt))
                ->value('staff_id');
        }

        if (!$staffId) {
            return $this->error('No staff available for the selected time slot.', 422);
        }

        DB::beginTransaction();
        try {
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
                DB::rollBack();
                return $this->error('Selected slot is no longer available.', 422);
            }

            $customer = Customer::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where(function ($q) use ($data) {
                    if (!empty($data['client_email'])) {
                        $q->where('email', $data['client_email']);
                    } else {
                        $q->where('phone', $data['client_phone'] ?? '')
                            ->where('name', $data['client_name']);
                    }
                })
                ->first();

            if (!$customer) {
                $customer = Customer::withoutGlobalScopes()->create([
                    'tenant_id' => $tenantId,
                    'name' => $data['client_name'],
                    'phone' => $data['client_phone'] ?? null,
                    'email' => $data['client_email'] ?? null,
                ]);
            }

            // Best-effort: link user_id when a logged-in customer books
            if ($request->bearerToken() && !$customer->user_id) {
                try {
                    $authUser = auth('api')->user();
                    if ($authUser && $authUser->hasRole('customer')) {
                        $customer->update(['user_id' => $authUser->id]);
                    }
                } catch (\Throwable) {
                    // Never fail the booking because of this
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

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error('Booking failed. Please try again.');
        }

        $appointment->load(['branch', 'staff', 'customer', 'services.service']);

        // Send booking confirmation email (best-effort)
        $customerEmail = $appointment->customer?->email;
        if (!empty($customerEmail)) {
            try {
                // Queue if possible; falls back to sync if queue driver is sync.
                Mail::to($customerEmail)->send(new BookingConfirmationMail($appointment));
            } catch (\Throwable $e) {
                // Don't fail booking if email fails.
                // If you want, we can later queue this instead.
            }
        }

        return $this->created(
            ['appointment' => (new PublicAppointmentResource($appointment))->resolve()],
            'Appointment booked successfully'
        );
    }

    private function resolveServiceWindows(int $tenantId, int $serviceId, int $branchId, Carbon $date)
    {
        $overrideRows = ServiceBranchAvailabilityOverride::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('service_id', $serviceId)
            ->where('branch_id', $branchId)
            ->where('date', $date->toDateString())
            ->orderBy('start_time')
            ->get();

        if ($overrideRows->isNotEmpty()) {
            if ($overrideRows->contains(fn($r) => (bool) $r->is_closed)) {
                return collect();
            }
            return $overrideRows->map(fn($r) => [
                'start_time' => (string) $r->start_time,
                'end_time' => (string) $r->end_time,
                'slot_minutes' => $r->slot_minutes,
            ])->filter(fn($w) => !empty($w['start_time']) && !empty($w['end_time']))->values();
        }

        return ServiceBranchAvailability::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('service_id', $serviceId)
            ->where('branch_id', $branchId)
            ->where('day_of_week', $date->dayOfWeek)
            ->where('is_active', true)
            ->orderBy('start_time')
            ->get()
            ->map(fn($r) => [
                'start_time' => (string) $r->start_time,
                'end_time' => (string) $r->end_time,
                'slot_minutes' => $r->slot_minutes,
            ]);
    }

    private function isWithinServiceWindow(Carbon $startAt, Carbon $endAt, $windows): bool
    {
        foreach ($windows as $window) {
            $windowStart = Carbon::parse($startAt->toDateString() . ' ' . $window['start_time']);
            $windowEnd = Carbon::parse($startAt->toDateString() . ' ' . $window['end_time']);
            if ($startAt->gte($windowStart) && $endAt->lte($windowEnd)) {
                return true;
            }
        }
        return false;
    }
}
