<?php

namespace App\Mail;

use App\Models\Rfq;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use App\Support\EnquiryPdf;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
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
        // Reply-To is applied globally (sales inbox) in AppServiceProvider.
        return new Envelope(
            subject: $this->subjectLine ?: 'Request for Quotation — '.$this->rfq->reference,
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.vendor-quote-request');
    }

    /**
     * Attach this vendor's Request for Quotation as a PDF so vendors whose mail
     * clients strip or block the quote link still get the full item list.
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => EnquiryPdf::render($this->rfq, $this->vendor), EnquiryPdf::filename($this->rfq, $this->vendor))
                ->withMime('application/pdf'),
        ];
    }
}
