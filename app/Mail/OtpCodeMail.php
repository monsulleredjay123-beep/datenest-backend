<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OtpCodeMail extends Mailable
{
    private const SUBJECTS = [
        'register' => 'Verify your email for DateNest',
        'password_change' => 'DateNest: confirm your password change',
        'password_reset' => 'DateNest: reset your password',
        'account_delete' => 'DateNest: confirm account deletion',
    ];

    public function __construct(
        public string $code,
        public string $purpose,
        public int $minutes,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: self::SUBJECTS[$this->purpose] ?? 'Your DateNest verification code');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.otp');
    }
}