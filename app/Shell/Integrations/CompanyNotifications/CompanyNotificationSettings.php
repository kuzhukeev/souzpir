<?php

namespace App\Shell\Integrations\CompanyNotifications;

use App\Models\Brand;
use Illuminate\Support\Facades\Crypt;

final class CompanyNotificationSettings
{
    public const EVENTS = ['handoff', 'unknown_question', 'report_published', 'limit_80'];

    public const CHANNELS = ['cabinet', 'email', 'telegram'];

    public function forBrand(Brand $brand): array
    {
        $settings = $brand->settings['company_notifications'] ?? [];
        $chat = $settings['telegram_chat'] ?? null;

        return [
            'telegram_configured' => is_string($chat) && $chat !== '',
            'telegram_mask' => is_string($chat) && $chat !== '' ? '••••'.substr(Crypt::decryptString($chat), -4) : null,
            'report_emails' => $settings['report_emails'] ?? [],
            'matrix' => $this->matrix($settings['matrix'] ?? []),
        ];
    }

    public function save(Brand $brand, ?string $chat, array $emails, ?array $matrix = null): void
    {
        $settings = $brand->settings ?? [];
        $current = $settings['company_notifications'] ?? [];
        $settings['company_notifications'] = [
            'telegram_chat' => $chat === null ? ($current['telegram_chat'] ?? null) : ($chat === '' ? null : Crypt::encryptString($chat)),
            'report_emails' => $emails,
            'matrix' => $this->matrix($matrix ?? ($current['matrix'] ?? [])),
        ];
        $brand->settings = $settings;
        $brand->save();
    }

    private function matrix(array $saved): array
    {
        $matrix = [];
        foreach (self::EVENTS as $event) {
            foreach (self::CHANNELS as $channel) {
                $matrix[$event][$channel] = ($saved[$event][$channel] ?? false) === true;
            }
        }

        return $matrix;
    }
}
