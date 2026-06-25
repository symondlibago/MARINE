<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Create a symbolic link from "public/storage" to "storage/app/public"
        if (!file_exists(public_path('storage'))) {
            app('files')->link(
                storage_path('app/public'), public_path('storage')
            );
        }

        // All outgoing mail replies go to the sales inbox, regardless of which
        // SMTP account or staff member sent it.
        if ($replyTo = config('mail.reply_to.address')) {
            Mail::alwaysReplyTo($replyTo, config('mail.reply_to.name'));
        }
    }
}
