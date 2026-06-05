<?php

namespace App\Mail;

use App\Models\Rfq;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VendorQuoteRequest extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Rfq $rfq,
        public Vendor $vendor,
        public string $link,
        public ?string $bodyMessage = null,
        public ?string $subjectLine = null,
        public ?User $staff = null,
    ) {}

    public function envelope(): Envelope
    {
        $replyTo = ($this->staff && $this->staff->email)
            ? [new Address($this->staff->email, $this->staff->name)]
            : [];

        return new Envelope(
            subject: $this->subjectLine ?: 'Request for Quotation — '.$this->rfq->reference,
            replyTo: $replyTo,
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.vendor-quote-request');
    }
}
