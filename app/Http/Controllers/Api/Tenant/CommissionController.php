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
use Illuminate\Validation\Rule;

class CommissionController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $rules = CommissionRule::query()->latest()->get();
            return $this->success($rules->map(fn($r) => $this->ruleResource($r))->values());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    private function ruleResource(CommissionRule $rule): array
    {
        return [
            'id'              => (string) $rule->id,
            'tenant_id'       => (string) $rule->tenant_id,
            'name'            => strtoupper((string) $rule->type) . ' commission',
            'rule_type'       => (string) $rule->type,
            'type'            => (string) $rule->type,
            'value'           => (float) $rule->value,
            'tier_threshold'  => $rule->tier_threshold !== null ? (float) $rule->tier_threshold : null,
            'staff_id'        => $rule->staff_id ? (string) $rule->staff_id : null,
            'service_id'      => $rule->service_id ? (string) $rule->service_id : null,
            'is_active'       => (bool) $rule->is_active,
            'created_at'      => $rule->created_at,
        ];
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'type'            => ['required', Rule::in(['percent_service', 'percent_product', 'flat_per_service', 'tiered'])],
                'value'           => 'required|numeric|min:0.01',
                'tier_threshold'  => 'nullable|numeric|min:0',
                'staff_id'        => 'nullable|exists:staff,id',
                'service_id'      => 'nullable|exists:services,id',
                'is_active'       => 'boolean',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $rule = CommissionRule::create(array_merge($data, [
            'tenant_id' => auth()->user()->tenant_id,
            'is_active' => $data['is_active'] ?? true,
        ]));

        return $this->success($this->ruleResource($rule), 201);
    }

    public function update(Request $request, CommissionRule $commission): JsonResponse
    {
        try {
            $data = $request->validate([
                'type'           => ['sometimes', Rule::in(['percent_service', 'percent_product', 'flat_per_service', 'tiered'])],
                'value'          => 'sometimes|numeric|min:0.01',
                'tier_threshold' => 'nullable|numeric|min:0',
                'staff_id'       => 'nullable|exists:staff,id',
                'service_id'     => 'nullable|exists:services,id',
                'is_active'      => 'boolean',
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        $commission->update($data);

        return $this->success($this->ruleResource($commission->fresh()));
    }

    public function destroy(CommissionRule $commission): JsonResponse
    {
        $commission->delete();
        return response()->json(null, 204);
    }

    public function show(CommissionRule $commission): JsonResponse
    {
        return $this->success($this->ruleResource($commission));
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

