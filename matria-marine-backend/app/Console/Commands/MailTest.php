<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class MailTest extends Command
{
    protected $signature = 'mail:test {to : Recipient email address}';

    protected $description = 'Send a test email to verify the current SMTP settings';

    public function handle(): int
    {
        $to = $this->argument('to');

        $this->info('Sending test email…');
        $this->line('  Host: '.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port'));
        $this->line('  From: '.config('mail.from.address'));
        $this->line('  To:   '.$to);

        try {
            Mail::raw('This is a test email from the Matria Marine portal. If you received this, your SMTP settings are working correctly.', function ($m) use ($to) {
                $m->to($to)->subject('Matria Marine — SMTP test');
            });

            $this->newLine();
            $this->info('✓ Sent successfully. Check the inbox (and the spam folder).');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('✗ Failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
