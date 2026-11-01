<?php

namespace App\Shell\Integrations\CompanyNotifications;

use App\Models\Brand;
use Illuminate\Support\Facades\Crypt;

final class CompanyNotificationSettings
{
    public function forBrand(Brand $brand): array
    {
        $settings = $brand->settings['company_notifications'] ?? [];
        $chat = $settings['telegram_chat'] ?? null;

        return [
            'telegram_configured' => is_string($chat) && $chat !== '',
            'telegram_mask' => is_string($chat) && $chat !== '' ? '••••'.substr(Crypt::decryptString($chat), -4) : null,
            'report_emails' => $settings['report_emails'] ?? [],
        ];
    }

    public function save(Brand $brand, ?string $chat, array $emails): void
    {
        $settings = $brand->settings ?? [];
        $current = $settings['company_notifications'] ?? [];
        $settings['company_notifications'] = [
            'telegram_chat' => $chat === null ? ($current['telegram_chat'] ?? null) : ($chat === '' ? null : Crypt::encryptString($chat)),
            'report_emails' => $emails,
        ];
        $brand->settings = $settings;
        $brand->save();
    }
}
