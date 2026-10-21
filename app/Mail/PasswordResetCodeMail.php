<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The 4-digit code for resetting a password by email. Plain text on purpose (no tracking, no images). */
class PasswordResetCodeMail extends Mailable
{
    public function __construct(
        public readonly string $code,
        public readonly int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your OneHealth password reset code');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.password-reset-code');
    }
}
