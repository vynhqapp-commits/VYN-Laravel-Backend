<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public string $purpose,
        public int $expiresInMinutes = 10,
    ) {}

    public function envelope(): Envelope
    {
        $subject = match ($this->purpose) {
            'register' => 'Your verification code',
            'reset_password' => 'Reset your password',
            default => 'Your login code',
        };

        return new Envelope(
            subject: "$subject — " . config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.otp',
        );
    }
}
