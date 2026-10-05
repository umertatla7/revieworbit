<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Models\PlatformMailSetting;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PlatformMailConfigurator
{
    private readonly string $baseDefault;

    private readonly array $baseSmtp;

    private readonly array $baseFrom;

    public function __construct()
    {
        $this->baseDefault = (string) config('mail.default', 'log');
        $this->baseSmtp = (array) config('mail.mailers.smtp', []);
        $this->baseFrom = (array) config('mail.from', []);
    }

    public function apply(?PlatformMailSetting $setting = null): bool
    {
        try {
            if (! Schema::hasTable('platform_mail_settings')) {
                return $this->restoreDefaults();
            }

            $setting ??= PlatformMailSetting::query()->latest()->first();
            if (! $setting?->enabled) {
                return $this->restoreDefaults();
            }

            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp' => [
                    'transport' => 'smtp',
                    'scheme' => $setting->encryption === 'ssl' ? 'smtps' : 'smtp',
                    'url' => null,
                    'host' => $setting->host,
                    'port' => $setting->port,
                    'username' => $setting->username,
                    'password' => $setting->password,
                    'timeout' => 15,
                    'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST),
                ],
                'mail.from' => [
                    'address' => $setting->from_address,
                    'name' => $setting->from_name,
                ],
            ]);
            $this->purgeSmtpMailer();

            return true;
        } catch (Throwable) {
            return $this->restoreDefaults();
        }
    }

    private function restoreDefaults(): bool
    {
        config([
            'mail.default' => $this->baseDefault,
            'mail.mailers.smtp' => $this->baseSmtp,
            'mail.from' => $this->baseFrom,
        ]);
        $this->purgeSmtpMailer();

        return false;
    }

    private function purgeSmtpMailer(): void
    {
        $manager = app('mail.manager');
        if ($manager instanceof MailManager) {
            $manager->purge('smtp');
        }
    }
}
