<?php

namespace App\Mail;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AppointmentReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $mailLocale;

    public function __construct(
        public Appointment $appointment,
        public string $reminderType,
        string $locale = 'en',
    ) {
        $this->mailLocale = in_array($locale, ['en', 'ar', 'fr']) ? $locale : 'en';
    }

    public function envelope(): Envelope
    {
        $serviceName = $this->appointment->services->first()?->service?->name ?? 'Appointment';
        $prefix = $this->reminderType === '24h' ? '24h reminder' : '1h reminder';

        return new Envelope(
            subject: "{$prefix}: {$serviceName} — " . config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.appointment-reminder',
        );
    }
}
