<?php

namespace App\Providers;

use App\Contracts\Push\AndroidPushClient;
use App\Contracts\Whispers\WhisperImageProcessor;
use App\Support\Push\FcmHttpV1Client;
use App\Support\Whispers\GdWhisperImageProcessor;
use App\Support\Whispers\UnsupportedWhisperImageProcessor;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AndroidPushClient::class, FcmHttpV1Client::class);
        $this->app->bind(WhisperImageProcessor::class, function () {
            return extension_loaded('gd')
                ? new GdWhisperImageProcessor
                : new UnsupportedWhisperImageProcessor;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
