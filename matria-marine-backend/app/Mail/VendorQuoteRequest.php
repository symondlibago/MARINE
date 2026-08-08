<?php

namespace App\Mail;

use App\Models\Rfq;
use App\Models\RfqItem;
use App\Models\RfqItemAttachment;
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
     * the files belonging to the lines this vendor was actually asked to quote
     * (photo of the part, drawing, spec sheet).
     *
     * Files travel with their line, so a vendor sent only lines 1 and 4 never
     * receives the drawing for line 7. Enquiry-wide files are the customer's
     * own paperwork and are never attached.
     */
    public function attachments(): array
    {
        $attachments = [
            Attachment::fromData(fn () => EnquiryPdf::render($this->rfq, $this->vendor), EnquiryPdf::filename($this->rfq, $this->vendor))
                ->withMime('application/pdf'),
        ];

        // Which lines this vendor was sent. No pivot rows means the whole enquiry.
        $askedIds = $this->rfq->rfqVendors()
            ->with('items:id')
            ->where('vendor_id', $this->vendor->id)
            ->first()?->items->pluck('id')->all() ?? [];

        $files = RfqItemAttachment::query()
            ->whereIn('rfq_item_id', RfqItem::where('rfq_id', $this->rfq->id)
                ->when($askedIds, fn ($q) => $q->whereIn('id', $askedIds))
                ->select('id'))
            ->orderBy('rfq_item_id')
            ->orderBy('id')
            ->get();

        $budget = self::MAX_SHARED_BYTES;

        foreach ($files as $file) {
            // Skip rather than build an email the vendor's server will bounce.
            if ($file->size > $budget) {
                Log::warning('Line-item attachment skipped — would exceed the email size limit.', [
                    'rfq' => $this->rfq->reference,
                    'vendor' => $this->vendor->name,
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
