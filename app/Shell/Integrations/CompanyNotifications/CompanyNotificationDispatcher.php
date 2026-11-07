<?php

namespace App\Shell\Integrations\CompanyNotifications;

use App\Models\Brand;
use App\Shell\Notifications\DomainEvent;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CompanyNotificationDispatcher
{
    public const EVENTS = [
        'seller.conversation.handed_off' => ['handoff', 'Диалог ждёт менеджера'],
        'seller.unknown_question.logged' => ['unknown_question', 'Неизвестный вопрос'],
        'vision.report.published' => ['report_published', 'Отчёт опубликован'],
        'seller.quota.threshold_reached' => ['limit_80', 'Лимит тарифа 80%'],
    ];

    public function enqueue(DomainEvent $event): void
    {
        $liveDelivery = CompanyNotificationDelivery::available();
        if (! $liveDelivery && config('runtime.notification_driver') !== 'fake') {
            return;
        }
        $e = $event->envelope;
        $info = self::EVENTS[$e['type']] ?? null;
        if (! $info || ($e['type'] === 'seller.quota.threshold_reached' && (! is_numeric($e['data']['fraction']) || (float) $e['data']['fraction'] < 0.8))) {
            return;
        }
        $brand = Brand::withoutGlobalScopes()->where('organization_id', $e['organization_id'])->where('id', $e['brand_id'])->where('status', 'active')->first();
        $settings = $brand?->settings['company_notifications'] ?? [];
        $matrix = $settings['matrix'][$info[0]] ?? [];
        $destinations = [];
        if (($matrix['email'] ?? false) === true) {
            foreach ($settings['report_emails'] ?? [] as $address) {
                if (is_string($address) && $address !== '') {
                    $destinations[] = ['email', $address];
                }
            }
        }
        if (($matrix['telegram'] ?? false) === true && is_string($settings['telegram_chat'] ?? null) && $settings['telegram_chat'] !== '') {
            $destinations[] = ['telegram', Crypt::decryptString($settings['telegram_chat'])];
        }
        foreach ($destinations as [$channel, $destination]) {
            $id = (string) Str::ulid();
            $inserted = DB::table('company_notification_deliveries')->insertOrIgnore(['id' => $id, 'organization_id' => $e['organization_id'], 'brand_id' => $e['brand_id'], 'event_id' => $e['event_id'], 'event_key' => $e['type'], 'channel' => $channel, 'destination' => Crypt::encryptString($destination), 'destination_hash' => hash_hmac('sha256', mb_strtolower($destination), (string) config('app.key')), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            if ($inserted) {
                $liveDelivery ? DeliverCompanyNotification::dispatch($id)->afterCommit() : DeliverFakeCompanyNotification::dispatch($id)->afterCommit();
            }
        }
    }
}
