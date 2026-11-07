<?php

namespace Tests\Feature\Seller\Analytics;

use App\Seller\Analytics\ScheduleAnalytics;
use App\Seller\Analytics\SendAnalyticsDigests;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ScheduledDigestE704OTest extends TenancyTestCase
{
    public function test_weekly_fake_digest_uses_brand_timezone_and_isolated_data(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        DB::table('brands')->whereIn('id', [$brand->id, $other->id])->update(['timezone' => 'UTC']);
        foreach ([$brand, $other] as $item) {
            DB::table('seller_analytics_subscriptions')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'day' => 'mon', 'recipients' => json_encode([$item->id.'@example.test']), 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('conversations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'assigned_user_id' => $owner->id, 'client_type' => 'newbie', 'stage' => 'done', 'state' => '{}', 'created_at' => '2026-11-02 12:00:00+00', 'updated_at' => '2026-11-02 12:00:00+00']);
        Mail::swap(new MailManager(app()));
        CarbonImmutable::setTestNow('2026-11-09 09:00:00 UTC');
        try {
            $transport = Mail::mailer('array')->getSymfonyTransport();
            $transport->flush();
            self::assertSame(0, app(SendAnalyticsDigests::class)->handle());
            $messages = $transport->messages();
            self::assertCount(2, $messages);
            $texts = [];
            foreach ($messages as $sent) {
                $email = $sent->getOriginalMessage();
                $texts[$email->getTo()[0]->getAddress()] = $email->getTextBody();
            }
            self::assertStringContainsString('1', $texts[$brand->id.'@example.test']);
            self::assertStringContainsString('0', $texts[$other->id.'@example.test']);
            $transport->flush();
            CarbonImmutable::setTestNow('2026-11-09 08:00:00 UTC');
            app(SendAnalyticsDigests::class)->handle();
            self::assertCount(0, $transport->messages());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_schedule_registers_only_the_fake_command(): void
    {
        $schedule = app(Schedule::class);
        (new ScheduleAnalytics)->register($schedule);
        self::assertTrue(collect($schedule->events())->contains(fn ($event) => str_contains($event->command, 'seller:analytics-fake-digest')));
    }
}
