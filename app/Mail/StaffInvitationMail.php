<?php

namespace App\Mail;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StaffInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $salonName,
        public string $inviteUrl,
        public string $role,
        public ?string $branchName,
        public ?string $inviteeName,
        public ?CarbonInterface $expiresAt,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You're invited to join {$this->salonName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.staff-invitation',
        );
    }
}
