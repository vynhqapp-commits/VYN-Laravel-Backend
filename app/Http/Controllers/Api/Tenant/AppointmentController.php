<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Service;
use App\Models\ServiceProductUsage;
use App\Models\Staff;
use App\Models\StockMovement;
use App\Models\TimeBlock;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentController extends Controller
{
    private const STATUSES = [
        'pending',
        'scheduled',
        'confirmed',
        'checked_in',
        'in_progress',
        'completed',
        'cancelled',
        'no_show',
    ];

    private const TRANSITIONS = [
        'pending'     => ['scheduled', 'confirmed', 'cancelled', 'no_show'],
        'scheduled'   => ['confirmed', 'checked_in', 'cancelled', 'no_show'],
        'confirmed'   => ['checked_in', 'cancelled', 'no_show'],
        'checked_in'  => ['in_progress', 'completed', 'cancelled'],
        'in_progress' => ['completed', 'cancelled'],
        'completed'   => [],
        'cancelled'   => [],
        'no_show'     => [],
    ];

    public function index(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'branch_id' => 'nullable|exists:branches,id',
                'staff_id'  => 'nullable|exists:staff,id',
                'date'      => 'nullable|date_format:Y-m-d',
                'from'      => 'nullable|date_format:Y-m-d',
                'to'        => 'nullable|date_format:Y-m-d|after_or_equal:from',
                'status'    => 'nullable|string',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        try {
            $q = Appointment::query()->with(['branch', 'customer', 'staff', 'services.service'])->latest('starts_at');

            if (!empty($data['branch_id'])) $q->where('branch_id', $data['branch_id']);
            if (!empty($data['staff_id'])) $q->where('staff_id', $data['staff_id']);
            if (!empty($data['status'])) $q->where('status', $data['status']);
            if (!empty($data['date'])) $q->whereDate('starts_at', $data['date']);

            if (!empty($data['from']) && !empty($data['to'])) {
                $from = Carbon::parse($data['from'])->startOfDay();
                $to = Carbon::parse($data['to'])->endOfDay();
                $q->whereBetween('starts_at', [$from, $to]);
            } elseif (!empty($data['from'])) {
                $from = Carbon::parse($data['from'])->startOfDay();
                $q->where('starts_at', '>=', $from);
            } elseif (!empty($data['to'])) {
                $to = Carbon::parse($data['to'])->endOfDay();
                $q->where('starts_at', '<=', $to);
            }

            $appointments = $q->paginate(100);

            return $this->paginated($appointments);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function show(Appointment $appointment): JsonResponse
    {
        try {
            return $this->success($appointment->load(['branch', 'customer', 'staff', 'services.service']));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'branch_id'   => 'required|exists:branches,id',
                'customer_id' => 'required|exists:customers,id',
                'staff_id'    => 'nullable|exists:staff,id',
                'service_id'  => 'required|exists:services,id',
                'start_time'  => 'required|date',
                'end_time'    => 'required|date|after:start_time',
                'notes'       => 'nullable|string',
                'status'      => 'nullable|in:' . implode(',', self::STATUSES),
                'source'      => 'nullable|in:dashboard,walk_in,public,online',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        try {
            $service = Service::findOrFail($data['service_id']);
            $staffId = $data['staff_id'] ?? Staff::where('branch_id', $data['branch_id'])->value('id');
            if (!$staffId) return $this->error('No staff available', 422);

            $startsAt = Carbon::parse($data['start_time']);
            $endsAt = Carbon::parse($data['end_time']);

            $conflict = Appointment::query()
                ->where('branch_id', $data['branch_id'])
                ->where('staff_id', $staffId)
                ->whereIn('status', ['pending', 'scheduled', 'confirmed', 'checked_in', 'in_progress'])
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt)
                ->exists();
            if ($conflict) return $this->error('Staff is already booked for this time.', 422);

            $blocked = TimeBlock::query()
                ->where('branch_id', $data['branch_id'])
                ->where(function ($q) use ($staffId) {
                    $q->whereNull('staff_id')->orWhere('staff_id', $staffId);
                })
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt)
                ->exists();
            if ($blocked) return $this->error('Selected time is blocked.', 422);

            $appointment = Appointment::create([
                'tenant_id'   => auth('api')->user()?->tenant_id,
                'branch_id'   => $data['branch_id'],
                'customer_id' => $data['customer_id'],
                'staff_id'    => $staffId,
                'starts_at'   => $startsAt,
                'ends_at'     => $endsAt,
                'status'      => $data['status'] ?? 'scheduled',
                'source'      => $data['source'] ?? 'dashboard',
                'notes'       => $data['notes'] ?? null,
            ]);

            AppointmentService::create([
                'appointment_id'   => $appointment->id,
                'service_id'       => $service->id,
                'price'            => $service->price,
                'duration_minutes' => $service->duration_minutes,
            ]);

            return $this->created($appointment->load(['branch', 'customer', 'staff', 'services.service']));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function update(Request $request, Appointment $appointment): JsonResponse
    {
        try {
            $data = $request->validate([
                'status' => 'sometimes|in:' . implode(',', self::STATUSES),
                'notes'  => 'nullable|string',
                // Optional rescheduling (do not change status unless `status` is explicitly provided)
                'start_time' => 'sometimes|required_with:end_time|date',
                'end_time' => 'sometimes|required_with:start_time|date|after:start_time',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        try {
            $isReschedule = array_key_exists('start_time', $data) || array_key_exists('end_time', $data);

            if (array_key_exists('status', $data)) {
                $from = (string) $appointment->status;
                $to = (string) $data['status'];
                $allowed = self::TRANSITIONS[$from] ?? [];
                if ($to !== $from && ! in_array($to, $allowed, true)) {
                    return $this->error("Invalid status transition: {$from} → {$to}", 422);
                }
            }

            DB::beginTransaction();

            if ($isReschedule) {
                // Only allow rescheduling for active appointments.
                $active = ['pending', 'scheduled', 'confirmed', 'checked_in'];
                if (! in_array((string) $appointment->status, $active, true)) {
                    return $this->error('Appointment cannot be rescheduled in its current status.', 422);
                }
            }

            // If completing, deduct inventory for service recipes (BOM) before we finalize status.
            if (array_key_exists('status', $data) && (string) $data['status'] === 'completed' && (string) $appointment->status !== 'completed') {
                $this->deductInventoryForAppointment($appointment);
            }

            $updateData = $data;
            if ($isReschedule) {
                // Map frontend payload into model fields.
                $nextStarts = Carbon::parse((string) $data['start_time']);
                $nextEnds = Carbon::parse((string) $data['end_time']);

                $conflict = Appointment::query()
                    ->where('branch_id', $appointment->branch_id)
                    ->where('staff_id', $appointment->staff_id)
                    ->whereIn('status', ['pending', 'scheduled', 'confirmed', 'checked_in', 'in_progress'])
                    ->where('id', '!=', $appointment->id)
                    ->where('starts_at', '<', $nextEnds)
                    ->where('ends_at', '>', $nextStarts)
                    ->exists();
                if ($conflict) return $this->error('Staff is already booked for this time.', 422);

                $blocked = TimeBlock::query()
                    ->where('branch_id', $appointment->branch_id)
                    ->where(function ($q) use ($appointment) {
                        $q->whereNull('staff_id')->orWhere('staff_id', $appointment->staff_id);
                    })
                    ->where('starts_at', '<', $nextEnds)
                    ->where('ends_at', '>', $nextStarts)
                    ->exists();
                if ($blocked) return $this->error('Selected time is blocked.', 422);

                $updateData['starts_at'] = $nextStarts;
                $updateData['ends_at'] = $nextEnds;
                unset($updateData['start_time'], $updateData['end_time']);
            }

            $appointment->update($updateData);

            DB::commit();
            return $this->success($appointment->load(['branch', 'customer', 'staff', 'services.service']), 'Appointment updated');
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error($e->getMessage());
        }
    }

    private function deductInventoryForAppointment(Appointment $appointment): void
    {
        $appointment->loadMissing(['services.service']);

        $tenantId = auth('api')->user()?->tenant_id;
        if (!$tenantId) {
            throw new \RuntimeException('Tenant required');
        }

        foreach ($appointment->services as $line) {
            /** @var AppointmentService $line */
            $serviceId = (int) $line->service_id;
            $usages = ServiceProductUsage::query()->where('service_id', $serviceId)->get();

            foreach ($usages as $usage) {
                $qtyNeeded = (float) $usage->quantity;
                if ($qtyNeeded <= 0) continue;

                // For now, we only support integer stock on inventory; round up consumption.
                $consume = (int) ceil($qtyNeeded);

                /** @var Inventory $inv */
                $inv = Inventory::query()->firstOrCreate([
                    'tenant_id' => $tenantId,
                    'branch_id' => (int) $appointment->branch_id,
                    'product_id' => (int) $usage->product_id,
                ], [
                    'quantity' => 0,
                ]);

                $newQty = (int) $inv->quantity - $consume;
                if ($newQty < 0) {
                    throw new \RuntimeException('Insufficient stock to complete appointment');
                }

                $inv->update(['quantity' => $newQty]);

                StockMovement::create([
                    'tenant_id' => $tenantId,
                    'branch_id' => (int) $appointment->branch_id,
                    'product_id' => (int) $usage->product_id,
                    'type' => 'service_deduction',
                    'quantity' => $consume,
                    'reason' => 'service_use',
                    'reference_type' => Appointment::class,
                    'reference_id' => $appointment->id,
                ]);
            }
        }
    }

    public function destroy(Appointment $appointment): JsonResponse
    {
        try {
            $appointment->delete();
            return $this->success(null, 'Appointment cancelled');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }
}

