<?php

namespace App\Mail;

use App\Models\Quote;
use App\Services\Erp\PdfRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QuoteMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Quote $quote, public ?string $personalMessage = null)
    {
        $this->onQueue(config('scoring.queues.notifications', 'notifications'));
    }

    public function envelope(): Envelope
    {
        $sender = $this->quote->creator;

        return new Envelope(
            from: new Address(config('company.email'), config('company.name')),
            replyTo: $sender ? [new Address($sender->email, $sender->name)] : [],
            subject: "Ihr Angebot {$this->quote->number} · ".config('company.name'),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.quote', with: [
            'quote' => $this->quote,
            'lead' => $this->quote->lead,
            'sender' => $this->quote->creator,
            'personalMessage' => $this->personalMessage,
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => app(PdfRenderer::class)->quote($this->quote), $this->quote->number.'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
