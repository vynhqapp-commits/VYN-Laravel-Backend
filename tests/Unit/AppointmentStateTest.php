<?php

namespace Tests\Unit;

use App\Services\Appointments\AppointmentState;
use PHPUnit\Framework\TestCase;

class AppointmentStateTest extends TestCase
{
    public function test_allows_same_status(): void
    {
        $this->assertTrue(AppointmentState::allowsTransition('scheduled', 'scheduled'));
    }

    public function test_allows_valid_transition(): void
    {
        $this->assertTrue(AppointmentState::allowsTransition('scheduled', 'confirmed'));
    }

    public function test_rejects_invalid_transition(): void
    {
        $this->assertFalse(AppointmentState::allowsTransition('scheduled', 'completed'));
    }
}
