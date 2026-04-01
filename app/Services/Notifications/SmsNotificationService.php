<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Log;

class SmsNotificationService
{
    public function send(string $to, string $message): void
    {
        // Provider integration can be plugged in here later (Twilio/Vonage/etc).
        Log::info('sms.notification.sent', [
            'to' => $to,
            'message' => $message,
        ]);
    }
}
