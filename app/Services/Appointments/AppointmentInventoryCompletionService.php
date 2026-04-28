<?php

namespace App\Services\Appointments;

use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\Inventory;
use App\Models\ServiceProductUsage;
use App\Models\StockMovement;

class AppointmentInventoryCompletionService
{
    public function deductForCompletion(Appointment $appointment, int $tenantId): void
    {
        $appointment->loadMissing(['services.service']);

        foreach ($appointment->services as $line) {
            /** @var AppointmentService $line */
            $serviceId = (int) $line->service_id;
            $usages = ServiceProductUsage::query()->where('service_id', $serviceId)->get();

            foreach ($usages as $usage) {
                $qtyNeeded = (float) $usage->quantity;
                if ($qtyNeeded <= 0) {
                    continue;
                }

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
}
