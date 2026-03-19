<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\CommissionEntry;
use App\Models\CommissionRule;
use App\Models\TipAllocation;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CommissionController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $rules = CommissionRule::query()->latest()->get();
            $rows = $rules->map(function (CommissionRule $r) {
                return [
                    'id' => (string) $r->id,
                    'tenant_id' => (string) $r->tenant_id,
                    'name' => strtoupper((string) $r->type) . ' commission',
                    'rule_type' => (string) $r->type,
                    'config' => [
                        'value' => (string) $r->value,
                        'tier_threshold' => $r->tier_threshold !== null ? (string) $r->tier_threshold : null,
                        'staff_id' => $r->staff_id ? (string) $r->staff_id : null,
                        'service_id' => $r->service_id ? (string) $r->service_id : null,
                        'is_active' => (bool) $r->is_active,
                    ],
                    'staff_id' => $r->staff_id ? (string) $r->staff_id : null,
                    'role' => null,
                ];
            })->values();

            return $this->success($rows);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function show(CommissionRule $commission): JsonResponse
    {
        try {
            return $this->success([
                'id' => (string) $commission->id,
                'tenant_id' => (string) $commission->tenant_id,
                'name' => strtoupper((string) $commission->type) . ' commission',
                'rule_type' => (string) $commission->type,
                'config' => [
                    'value' => (string) $commission->value,
                    'tier_threshold' => $commission->tier_threshold !== null ? (string) $commission->tier_threshold : null,
                    'staff_id' => $commission->staff_id ? (string) $commission->staff_id : null,
                    'service_id' => $commission->service_id ? (string) $commission->service_id : null,
                    'is_active' => (bool) $commission->is_active,
                ],
                'staff_id' => $commission->staff_id ? (string) $commission->staff_id : null,
                'role' => null,
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function staffEarnings(Request $request, int $staffId): JsonResponse
    {
        try {
            $data = $request->validate([
                'from' => 'nullable|date_format:Y-m-d',
                'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        try {
            $from = !empty($data['from']) ? Carbon::parse($data['from'])->startOfDay() : null;
            $to = !empty($data['to']) ? Carbon::parse($data['to'])->endOfDay() : null;

            $commQ = CommissionEntry::query()->where('staff_id', $staffId);
            if ($from && $to) $commQ->whereBetween('created_at', [$from, $to]);
            elseif ($from) $commQ->where('created_at', '>=', $from);
            elseif ($to) $commQ->where('created_at', '<=', $to);

            $tipQ = TipAllocation::query()->where('staff_id', $staffId);
            if ($from && $to) $tipQ->whereBetween('earned_at', [$from, $to]);
            elseif ($from) $tipQ->where('earned_at', '>=', $from);
            elseif ($to) $tipQ->where('earned_at', '<=', $to);

            $commissionRows = $commQ->latest()->limit(200)->get()->map(function (CommissionEntry $c) {
                return [
                    'id' => (string) $c->id,
                    'staff_id' => (string) $c->staff_id,
                    'amount' => (string) $c->commission_amount,
                    'type' => 'commission',
                    'reversed_at' => $c->status === 'reversed' ? optional($c->updated_at)->toISOString() : null,
                ];
            });

            $tipRows = $tipQ->latest('earned_at')->limit(200)->get()->map(function (TipAllocation $t) {
                return [
                    'id' => (string) $t->id,
                    'staff_id' => (string) $t->staff_id,
                    'amount' => (string) $t->amount,
                    'type' => 'tip',
                    'reversed_at' => null,
                ];
            });

            return $this->success($commissionRows->concat($tipRows)->values());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }
}

