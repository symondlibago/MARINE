<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Verifies the Cloudflare R2 connection in isolation: uploads a tiny file,
 * reads it back, tries a signed URL, then deletes it. Run: php artisan r2:test
 */
class R2Test extends Command
{
    protected $signature = 'r2:test';

    protected $description = 'Verify the R2 (S3) connection: upload, read back, sign, and delete a test file.';

    public function handle(): int
    {
        $disk = Storage::disk('r2');
        $bucket = config('filesystems.disks.r2.bucket');
        $endpoint = config('filesystems.disks.r2.endpoint');

        $this->line("Bucket:   {$bucket}");
        $this->line("Endpoint: {$endpoint}");
        $this->newLine();

        $path = 'connection-tests/'.now()->format('Ymd_His').'_'.bin2hex(random_bytes(3)).'.txt';
        $body = 'Matria R2 connectivity test at '.now()->toIso8601String();

        try {
            $this->info("1) Uploading {$path} …");
            $disk->put($path, $body);

            $this->info('2) Reading it back …');
            if ($disk->get($path) !== $body) {
                $this->error('   Content mismatch — wrote and read different data.');

                return self::FAILURE;
            }
            $this->line('   ✔ content matches');

            $this->info('3) Generating a temporary signed URL (5 min) …');
            try {
                $this->line('   '.$disk->temporaryUrl($path, now()->addMinutes(5)));
            } catch (\Throwable $e) {
                $this->warn('   signed URL unavailable: '.$e->getMessage());
            }

            $this->info('4) Deleting the test file …');
            $disk->delete($path);
            $this->line('   ✔ deleted');

            $this->newLine();
            $this->info("✅ R2 OK — \"{$bucket}\" is reachable, writable, and readable.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('❌ R2 FAILED: '.$e->getMessage());
            $this->newLine();
            $this->line('Likely causes:');
            $this->line('  • "AccessDenied"  → the API token isn\'t scoped to this bucket.');
            $this->line('  • "could not resolve host" / endpoint error → wrong R2_URL, or try R2_USE_PATH_STYLE=true in .env.');
            $this->line('  • SSL/cURL cert error → TLS interception (verify is already off in dev).');

            return self::FAILURE;
        }
    }
}
