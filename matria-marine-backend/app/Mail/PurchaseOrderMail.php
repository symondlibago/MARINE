<?php

namespace App\Mail;

use App\Models\PurchaseOrder;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
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
        $replyTo = ($this->staff && $this->staff->email)
            ? [new Address($this->staff->email, $this->staff->name)]
            : [];

        return new Envelope(
            subject: 'Purchase Order '.$this->po->po_number.' — Matria Marine Services',
            replyTo: $replyTo,
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.purchase-order');
    }

    public function attachments(): array
    {
        $pdf = Pdf::loadView('pdf.purchase-order', ['po' => $this->po]);

        return [
            Attachment::fromData(fn () => $pdf->output(), ($this->po->po_number ?: 'PO').'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
