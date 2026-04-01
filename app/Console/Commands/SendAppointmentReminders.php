<?php

namespace App\Console\Commands;

use App\Jobs\SendAppointmentReminderJob;
use App\Models\Appointment;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendAppointmentReminders extends Command
{
    protected $signature = 'appointments:send-reminders {--window=5 : Reminder window in minutes}';

    protected $description = 'Dispatch 24h and 1h appointment reminders';

    public function handle(): int
    {
        $windowMinutes = max(1, (int) $this->option('window'));

        $queued24h = $this->queueDueReminders(
            hoursBefore: 24,
            sentAtColumn: 'reminder_24h_sent_at',
            reminderType: '24h',
            windowMinutes: $windowMinutes
        );

        $queued1h = $this->queueDueReminders(
            hoursBefore: 1,
            sentAtColumn: 'reminder_1h_sent_at',
            reminderType: '1h',
            windowMinutes: $windowMinutes
        );

        $this->info("Queued reminders — 24h: {$queued24h}, 1h: {$queued1h}");

        return self::SUCCESS;
    }

    private function queueDueReminders(
        int $hoursBefore,
        string $sentAtColumn,
        string $reminderType,
        int $windowMinutes
    ): int {
        $from = Carbon::now()->addHours($hoursBefore);
        $to = $from->copy()->addMinutes($windowMinutes);
        $queued = 0;

        Appointment::withoutGlobalScopes()
            ->whereIn('status', ['scheduled', 'confirmed'])
            ->whereNull($sentAtColumn)
            ->whereBetween('starts_at', [$from, $to])
            ->orderBy('id')
            ->chunkById(100, function ($appointments) use ($sentAtColumn, $reminderType, &$queued): void {
                foreach ($appointments as $appointment) {
                    $marked = Appointment::withoutGlobalScopes()
                        ->whereKey($appointment->id)
                        ->whereNull($sentAtColumn)
                        ->update([$sentAtColumn => Carbon::now()]);

                    if (!$marked) {
                        continue;
                    }

                    SendAppointmentReminderJob::dispatch((int) $appointment->id, $reminderType);
                    $queued++;
                }
            });

        return $queued;
    }
}
