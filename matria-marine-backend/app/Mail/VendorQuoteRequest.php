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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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

    /** Total size of the shared files we are willing to put on one email. */
    private const MAX_SHARED_BYTES = 15 * 1024 * 1024;

    /**
     * Attach this vendor's Request for Quotation as a PDF so vendors whose mail
     * clients strip or block the quote link still get the full item list, plus
     * any enquiry files explicitly marked "send to vendors" (drawings, spec
     * sheets, photos of the part).
     */
    public function attachments(): array
    {
        $attachments = [
            Attachment::fromData(fn () => EnquiryPdf::render($this->rfq, $this->vendor), EnquiryPdf::filename($this->rfq, $this->vendor))
                ->withMime('application/pdf'),
        ];

        // Internal files are never included — only those deliberately shared.
        $shared = $this->rfq->attachments()
            ->where('share_with_vendors', true)
            ->orderBy('id')
            ->get();

        $budget = self::MAX_SHARED_BYTES;

        foreach ($shared as $file) {
            // Stop rather than build an email the vendor's server will bounce.
            if ($file->size > $budget) {
                Log::warning('Enquiry attachment skipped — would exceed the email size limit.', [
                    'rfq' => $this->rfq->reference,
                    'file' => $file->original_name,
                    'size' => $file->size,
                ]);

                continue;
            }

            $budget -= $file->size;

            $attachments[] = Attachment::fromData(
                fn () => Storage::disk($file->disk)->get($file->path),
                $file->original_name
            )->withMime($file->mime_type ?: 'application/octet-stream');
        }

        return $attachments;
    }
}
