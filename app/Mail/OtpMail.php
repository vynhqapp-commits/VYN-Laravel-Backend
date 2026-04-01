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
        public string $mailLocale = 'en',
    ) {}

    public function envelope(): Envelope
    {
        $subjects = [
            'en' => ['register' => 'Your verification code', 'reset_password' => 'Reset your password', 'login' => 'Your login code'],
            'ar' => ['register' => 'رمز التحقق الخاص بك', 'reset_password' => 'إعادة تعيين كلمة المرور', 'login' => 'رمز تسجيل الدخول'],
            'fr' => ['register' => 'Votre code de vérification', 'reset_password' => 'Réinitialisez votre mot de passe', 'login' => 'Votre code de connexion'],
        ];

        $localeSubjects = $subjects[$this->mailLocale] ?? $subjects['en'];
        $subject = $localeSubjects[$this->purpose] ?? $localeSubjects['login'];

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
