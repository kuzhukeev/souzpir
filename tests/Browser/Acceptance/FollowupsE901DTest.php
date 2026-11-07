<?php

namespace Tests\Browser\Acceptance;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class FollowupsE901DTest extends TenancyTestCase
{
    public function test_two_fake_followups_then_stop_after_silence(): void
    {
        Carbon::setTestNow('2026-11-01 10:00:00+00:00');
        Http::preventStrayRequests();
        config(['queue.default' => 'sync', 'cache.default' => 'array']);
        try {
            [, $org, $brand, $other] = $this->cabinet();
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('brand_seller_settings')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'followup' => json_encode(['enabled' => true, 'first_hours' => 20, 'second_hours' => 40, 'max_auto' => 2, 'window' => null, 'texts' => ['Первое напоминание', 'Второе напоминание']], JSON_THROW_ON_ERROR), 'off_topic' => '{}', 'negative' => '{}', 'tools' => '{}', 'greetings' => '{}', 'phrases' => '{}', 'schedule' => '{}', 'assignment' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            $conversation = (string) Str::ulid();
            $foreign = (string) Str::ulid();
            foreach ([[$conversation, $brand->id], [$foreign, $other->id]] as [$id, $brandId]) {
                DB::table('conversations')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brandId, 'mode' => 'bot', 'stage' => 'need', 'state' => '{}', 'last_client_message_at' => now()->subMinute(), 'created_at' => now(), 'updated_at' => now()]);
                DB::table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brandId, 'conversation_id' => $id, 'role' => 'agent', 'text' => 'Исходный ответ', 'meta' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            }
            $process = fn () => $this->artisan('seller:prepare-followups')->assertSuccessful();
            Carbon::setTestNow('2026-11-02 05:59:00+00:00');
            $process();
            self::assertSame(1, DB::table('messages')->where('conversation_id', $conversation)->count());
            Carbon::setTestNow('2026-11-02 06:00:00+00:00');
            $process();
            $process();
            self::assertSame(1, DB::table('conversations')->where('id', $conversation)->value('followups_sent'));
            self::assertSame(1, DB::table('seller_followup_candidates')->where('conversation_id', $conversation)->where('status', 'completed')->count());
            Carbon::setTestNow('2026-11-03 22:00:00+00:00');
            $process();
            self::assertSame(2, DB::table('conversations')->where('id', $conversation)->value('followups_sent'));
            self::assertSame(['Исходный ответ', 'Первое напоминание', 'Второе напоминание'], DB::table('messages')->where('conversation_id', $conversation)->orderBy('created_at')->pluck('text')->all());
            Carbon::setTestNow('2026-11-10 10:00:00+00:00');
            $process();
            $process();
            self::assertSame(2, DB::table('seller_followup_candidates')->where('conversation_id', $conversation)->where('status', 'completed')->count());
            self::assertSame(3, DB::table('messages')->where('conversation_id', $conversation)->count());
            self::assertSame(1, DB::table('messages')->where('conversation_id', $foreign)->count());
            Http::assertNothingSent();
            Mail::assertNothingSent();
        } finally {
            Carbon::setTestNow();
        }
    }
}
