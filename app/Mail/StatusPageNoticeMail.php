<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Every email a status page sends to the public: the subscription confirmation, incident down/up notices
 * and announcements. Only public facts (page name, service name, state) — never URLs or error details.
 * Queued, so a page with many subscribers never slows the monitor that triggered it.
 */
class StatusPageNoticeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $pageName,
        public string $heading,
        public string $body,
        public string $actionUrl,
        public string $actionLabel,
        public ?string $unsubscribeUrl = null,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "[{$this->pageName}] {$this->heading}");
    }

    public function headers(): Headers
    {
        return new Headers(text: $this->unsubscribeUrl === null ? [] : ['List-Unsubscribe' => "<{$this->unsubscribeUrl}>"]);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.status-page-notice');
    }
}
