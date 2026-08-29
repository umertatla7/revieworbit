<?php

namespace App\Providers;

use App\Domain\Messaging\Contracts\MessagingProvider;
use App\Domain\Messaging\Services\FakeMessagingProvider;
use App\Domain\Messaging\Services\TwilioCredentials;
use App\Domain\Messaging\Services\TwilioMessagingProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(MessagingProvider::class, function ($app): MessagingProvider {
            return config('services.twilio.provider') === 'twilio' || $app->make(TwilioCredentials::class)->configured()
                ? $app->make(TwilioMessagingProvider::class)
                : $app->make(FakeMessagingProvider::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(fn (object $user, string $token): string => rtrim(config('services.frontend.url'), '/')
            .'/reset-password?token='.urlencode($token).'&email='.urlencode($user->getEmailForPasswordReset()));
    }
}
