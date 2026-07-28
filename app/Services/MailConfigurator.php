<?php

namespace App\Services;

use App\Models\EmailSender;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

class MailConfigurator
{
    public function apply(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        $host = SystemSetting::valueFor('mail.host', config('mail.mailers.smtp.host'));
        $port = (int) SystemSetting::valueFor('mail.port', config('mail.mailers.smtp.port', 587));
        $encryption = SystemSetting::valueFor('mail.encryption', config('mail.mailers.smtp.scheme', 'tls'));
        $username = SystemSetting::valueFor('mail.username', config('mail.mailers.smtp.username'));
        $password = SystemSetting::valueFor('mail.password', config('mail.mailers.smtp.password'));

        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.host', $host);
        Config::set('mail.mailers.smtp.port', $port);
        Config::set('mail.mailers.smtp.scheme', $encryption === 'ssl' ? 'smtps' : null);
        Config::set('mail.mailers.smtp.username', $username);
        Config::set('mail.mailers.smtp.password', $password);

        if (Schema::hasTable('email_senders')) {
            $sender = EmailSender::query()
                ->where('is_active', true)
                ->where('status', 'approved')
                ->orderByDesc('is_default')
                ->first();

            if ($sender) {
                Config::set('mail.from.address', $sender->email);
                Config::set('mail.from.name', $sender->name);
            }
        }
    }
}
