<?php

namespace App\Mail;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FranchiseOwnerInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $salonName,
        public string $inviteUrl,
        public ?string $inviteeName,
        public ?CarbonInterface $expiresAt,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You're invited as a franchise owner — {$this->salonName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.franchise-owner-invitation',
        );
    }
}

