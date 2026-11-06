<?php

namespace App\Seller\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

final class SendAnalyticsDigests extends Command
{
    protected $signature = 'seller:analytics-fake-digest';

    protected $description = 'Prepare scheduled analytics using only the in-memory transport';

    public function handle(): int
    {
        $subscriptions = DB::table('seller_analytics_subscriptions as s')
            ->join('brands as b', function ($join): void {
                $join->on('b.id', '=', 's.brand_id')->on('b.organization_id', '=', 's.organization_id');
            })->where('b.status', 'active')
            ->get(['s.organization_id', 's.brand_id', 's.day', 's.recipients', 'b.timezone']);

        foreach ($subscriptions as $subscription) {
            try {
                $local = CarbonImmutable::now($subscription->timezone ?: 'UTC');
            } catch (\Throwable) {
                continue;
            }
            if (strtolower($local->format('D')) !== $subscription->day || $local->format('H') !== '09') {
                continue;
            }
            $recipients = json_decode($subscription->recipients, true);
            if (! is_array($recipients) || $recipients === []) {
                continue;
            }
            $end = $local->startOfDay();
            $begin = $end->subDays(7);
            $count = DB::table('conversations')
                ->where('organization_id', $subscription->organization_id)->where('brand_id', $subscription->brand_id)
                ->where('created_at', '>=', $begin->utc())->where('created_at', '<', $end->utc())->count();
            $body = 'Диалогов за последние семь дней: '.$count;
            foreach ($recipients as $recipient) {
                if (! is_string($recipient) || ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                // This command cannot select a real mail transport.
                Mail::mailer('array')->raw($body, fn ($message) => $message->to($recipient)->subject('Тестовый еженедельный отчёт'));
            }
        }

        return self::SUCCESS;
    }
}
