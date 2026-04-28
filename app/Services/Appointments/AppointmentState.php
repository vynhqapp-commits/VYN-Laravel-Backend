<?php

namespace App\Services\Appointments;

/**
 * Appointment status values and allowed transitions (domain rules).
 */
final class AppointmentState
{
    public const STATUSES = [
        'pending',
        'scheduled',
        'confirmed',
        'checked_in',
        'in_progress',
        'completed',
        'cancelled',
        'no_show',
    ];

    /**
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        'pending' => ['scheduled', 'confirmed', 'cancelled', 'no_show'],
        'scheduled' => ['confirmed', 'checked_in', 'cancelled', 'no_show'],
        'confirmed' => ['checked_in', 'cancelled', 'no_show'],
        'checked_in' => ['in_progress', 'completed', 'cancelled'],
        'in_progress' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
        'no_show' => [],
    ];

    /**
     * Statuses that participate in staff double-booking checks.
     *
     * @return list<string>
     */
    public static function blockingBookingStatuses(): array
    {
        return ['pending', 'scheduled', 'confirmed', 'checked_in', 'in_progress'];
    }

    /**
     * @return list<string>
     */
    public static function reschedulableStatuses(): array
    {
        return ['pending', 'scheduled', 'confirmed', 'checked_in'];
    }

    public static function allowsTransition(string $from, string $to): bool
    {
        if ($to === $from) {
            return true;
        }

        $allowed = self::TRANSITIONS[$from] ?? [];

        return in_array($to, $allowed, true);
    }
}
