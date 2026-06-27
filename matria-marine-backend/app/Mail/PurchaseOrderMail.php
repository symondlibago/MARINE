<?php

namespace App\Mail;

use App\Models\PurchaseOrder;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PurchaseOrderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public PurchaseOrder $po,
        public ?User $staff = null,
        public ?string $link = null,
    ) {}

    public function envelope(): Envelope
    {
        // Reply-To is applied globally (sales inbox) in AppServiceProvider.
        return new Envelope(
            subject: 'Purchase Order '.$this->po->po_number.' — Matria Marine Services',
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.purchase-order');
    }

    public function attachments(): array
    {
        $logoPath = public_path('logo.png');
        $logo = is_file($logoPath) ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath)) : null;

        $pdf = Pdf::loadView('pdf.purchase-order', ['po' => $this->po, 'logo' => $logo]);

        return [
            Attachment::fromData(fn () => $pdf->output(), ($this->po->po_number ?: 'PO').'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
