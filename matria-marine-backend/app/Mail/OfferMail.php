<?php

namespace App\Mail;

use App\Models\Offer;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OfferMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Offer $offer,
        public ?User $staff = null,
        public ?string $link = null,
    ) {}

    public function envelope(): Envelope
    {
        // Reply-To is applied globally (sales inbox) in AppServiceProvider.
        return new Envelope(
            subject: 'Quotation '.$this->offer->offer_number.' — Matria Marine Services',
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.offer');
    }

    public function attachments(): array
    {
        $logoPath = public_path('logo.png');
        $logo = is_file($logoPath) ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath)) : null;

        $this->offer->loadMissing(['items', 'rfq:id,customer_reference,ship_name', 'creator:id,name,phone,email']);

        $pdf = Pdf::loadView('pdf.offer', [
            'offer' => $this->offer,
            'company' => config('procurement.company'),
            'logo' => $logo,
        ]);

        return [
            Attachment::fromData(fn () => $pdf->output(), ($this->offer->offer_number ?: 'quotation').'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
