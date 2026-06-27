<?php

namespace App\Mail;

use App\Models\ReturnNote;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReturnNoteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ReturnNote $rn,
        public ?User $staff = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Return Note '.$this->rn->rtn_number.' — Matria Marine Services',
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.return-note');
    }

    public function attachments(): array
    {
        $logoPath = public_path('logo.png');
        $logo = is_file($logoPath) ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath)) : null;

        $pdf = Pdf::loadView('pdf.return-note', ['rn' => $this->rn, 'logo' => $logo]);

        return [
            Attachment::fromData(fn () => $pdf->output(), ($this->rn->rtn_number ?: 'return-note').'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
