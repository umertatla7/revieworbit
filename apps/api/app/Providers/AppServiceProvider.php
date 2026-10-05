<?php

namespace App\Providers;

use App\Domain\Messaging\Contracts\MessagingProvider;
use App\Domain\Messaging\Services\FakeMessagingProvider;
use App\Domain\Messaging\Services\TwilioCredentials;
use App\Domain\Messaging\Services\TwilioMessagingProvider;
use App\Domain\Tenancy\Services\PlatformMailConfigurator;
use App\Models\PersonalAccessToken;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

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
    public function boot(PlatformMailConfigurator $mailConfigurator): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        $mailConfigurator->apply();
        Queue::before(function (JobProcessing $event) use ($mailConfigurator): void {
            $mailConfigurator->apply();
        });
        ResetPassword::createUrlUsing(fn (object $user, string $token): string => rtrim(config('services.frontend.url'), '/')
            .'/reset-password?token='.urlencode($token).'&email='.urlencode($user->getEmailForPasswordReset()));
    }
}
