<?php

namespace App\Mail;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $bookingLocale;

    public function __construct(
        public Appointment $appointment,
        string $locale = 'en',
    ) {
        $this->bookingLocale = in_array($locale, ['en', 'ar', 'fr']) ? $locale : 'en';
    }

    public function envelope(): Envelope
    {
        $serviceName = $this->appointment->services->first()?->service?->name ?? 'Appointment';

        $subjects = [
            'en' => "Booking confirmed: {$serviceName}",
            'ar' => "تم تأكيد الحجز: {$serviceName}",
            'fr' => "Réservation confirmée : {$serviceName}",
        ];

        return new Envelope(
            subject: ($subjects[$this->bookingLocale] ?? $subjects['en']) . ' — ' . config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.booking-confirmation',
        );
    }
}
