<?php

namespace Tests\Unit;

use App\Models\CommissionRule;
use App\Services\Commissions\CommissionCalculationService;
use Illuminate\Support\Collection;
use Tests\TestCase;

class CommissionCalculationServiceTest extends TestCase
{
    public function test_normalize_maps_legacy_aliases(): void
    {
        $svc = new CommissionCalculationService;
        $this->assertSame('percent_service', $svc->normalizeCommissionType('percentage'));
        $this->assertSame('flat_per_service', $svc->normalizeCommissionType('fixed'));
        $this->assertSame('tiered', $svc->normalizeCommissionType('tiered'));
    }

    public function test_calculate_percent_service(): void
    {
        $svc = new CommissionCalculationService;
        $rule = new CommissionRule([
            'type' => 'percent_service',
            'value' => 10,
        ]);
        $this->assertEqualsWithDelta(10.0, $svc->calculateCommissionAmount($rule, 100.0, 2), PHP_FLOAT_EPSILON);
    }

    public function test_resolve_prefers_matching_staff_and_service(): void
    {
        $svc = new CommissionCalculationService;
        $rules = new Collection([
            new CommissionRule(['id' => 1, 'staff_id' => 9, 'service_id' => 5, 'type' => 'percent_service', 'value' => 5]),
            new CommissionRule(['id' => 2, 'staff_id' => 1, 'service_id' => 5, 'type' => 'percent_service', 'value' => 15]),
            new CommissionRule(['id' => 3, 'staff_id' => 1, 'service_id' => null, 'type' => 'percent_service', 'value' => 8]),
        ]);
        $picked = $svc->resolveCommissionRule($rules, 1, 5, ['percent_service']);
        $this->assertNotNull($picked);
        $this->assertEqualsWithDelta(15.0, (float) $picked->value, 0.001);
    }
}
