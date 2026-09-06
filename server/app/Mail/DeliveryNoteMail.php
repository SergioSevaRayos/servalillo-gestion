<?php

namespace App\Mail;

use App\Models\DeliveryNote;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DeliveryNoteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public DeliveryNote $note) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Albarán {$this->note->number} · ".config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.delivery-note',
            with: ['note' => $this->note],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        if (! $this->note->pdf_path) {
            return [];
        }

        return [
            Attachment::fromStorageDisk('r2', $this->note->pdf_path)
                ->as($this->note->number.'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
