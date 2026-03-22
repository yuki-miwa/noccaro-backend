<?php

namespace App\Providers;

use App\Contracts\Push\AndroidPushClient;
use App\Support\Push\FcmHttpV1Client;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AndroidPushClient::class, FcmHttpV1Client::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
