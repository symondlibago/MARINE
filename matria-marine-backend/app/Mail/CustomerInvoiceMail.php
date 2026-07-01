<?php

namespace App\Mail;

use App\Models\CustomerInvoice;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerInvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public CustomerInvoice $invoice,
        public ?User $staff = null,
    ) {}

    public function envelope(): Envelope
    {
        // Reply-To is applied globally (sales inbox) in AppServiceProvider.
        return new Envelope(
            subject: 'Invoice '.$this->invoice->invoice_number.' — Matria Marine Services',
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.customer-invoice');
    }

    public function attachments(): array
    {
        $logoPath = public_path('logo.png');
        $logo = is_file($logoPath) ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath)) : null;

        $this->invoice->loadMissing(['items', 'rfq:id,reference', 'creator:id,name,phone']);

        $pdf = Pdf::loadView('pdf.customer-invoice', [
            'invoice' => $this->invoice,
            'company' => config('procurement.company'),
            'logo' => $logo,
        ]);

        return [
            Attachment::fromData(fn () => $pdf->output(), ($this->invoice->invoice_number ?: 'invoice').'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
