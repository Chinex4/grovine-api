<?php

namespace App\Mail;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OtpCodeMail extends Mailable
{
    use Queueable;

    public function __construct(
        public string $code,
        public string $purpose,
        public CarbonInterface $expiresAt,
    ) {
    }

    public function envelope(): Envelope
    {
        $subjectPurpose = $this->purpose === 'login' ? 'Login' : 'Signup';

        return new Envelope(
            subject: "Grovine {$subjectPurpose} OTP Code",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.otp-code',
        );
    }
}
