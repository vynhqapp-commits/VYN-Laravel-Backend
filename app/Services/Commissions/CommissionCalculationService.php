<?php

namespace App\Services\Commissions;

use App\Models\CommissionRule;
use Illuminate\Support\Collection;

class CommissionCalculationService
{
    public function normalizeCommissionType(string $type): string
    {
        return match ($type) {
            'percentage' => 'percent_service',
            'fixed' => 'flat_per_service',
            default => $type,
        };
    }

    /**
     * @param  Collection<int, CommissionRule>  $rules
     */
    public function resolveCommissionRule(Collection $rules, int $staffId, ?int $serviceId, array $allowedTypes): ?CommissionRule
    {
        $filtered = $rules->filter(function (CommissionRule $rule) use ($allowedTypes, $serviceId) {
            $normalized = $this->normalizeCommissionType((string) $rule->type);
            if (! in_array($normalized, $allowedTypes, true)) {
                return false;
            }

            if ($serviceId !== null) {
                return (int) ($rule->service_id ?? 0) === $serviceId;
            }

            return $rule->service_id === null;
        })->values();

        return $filtered
            ->sortBy([
                fn (CommissionRule $rule) => $rule->staff_id === $staffId ? 0 : 1,
                fn (CommissionRule $rule) => $serviceId !== null && (int) ($rule->service_id ?? 0) === $serviceId ? 0 : 1,
                fn (CommissionRule $rule) => (int) $rule->id,
            ])
            ->first();
    }

    public function calculateCommissionAmount(CommissionRule $rule, float $baseAmount, int $quantity = 1): float
    {
        $type = $this->normalizeCommissionType((string) $rule->type);
        if ($baseAmount <= 0) {
            return 0.0;
        }

        if ($type === 'percent_service' || $type === 'percent_product') {
            return $baseAmount * ((float) $rule->value / 100.0);
        }

        if ($type === 'flat_per_service') {
            return ((float) $rule->value) * max(1, $quantity);
        }

        if ($type === 'tiered') {
            $threshold = (float) ($rule->tier_threshold ?? 0);
            if ($threshold > 0 && $baseAmount >= $threshold) {
                return $baseAmount * ((float) $rule->value / 100.0);
            }
        }

        return 0.0;
    }
}
