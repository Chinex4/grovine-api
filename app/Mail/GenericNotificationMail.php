<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class GenericNotificationMail extends Mailable
{
    use Queueable;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $title,
        public string $messageText,
        public ?string $actionUrl = null,
        public array $data = [],
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.generic-notification',
        );
    }
}
