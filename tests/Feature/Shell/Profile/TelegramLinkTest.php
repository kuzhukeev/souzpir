<?php

namespace Tests\Feature\Shell\Profile;

use App\Shell\Profile\TelegramLinks;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Tenancy\TenancyTestCase;

final class TelegramLinkTest extends TenancyTestCase
{
    private const SECRET = 'synthetic-webhook-secret-with-more-than-32-chars';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withServerVariables(['SERVER_ADDR' => '127.0.0.1', 'REMOTE_ADDR' => '127.0.0.1']);
        config(['features.E1-08.defaults.telegram_bot_username' => 'souzpir_test_bot', 'features.E1-08.defaults.telegram_webhook_secret' => self::SECRET]);
        Http::preventStrayRequests();
    }

    private function start(object $user, object $brand): string
    {
        $this->actingAs($user)->withSession(['brand_id' => $brand->id])->post('/profile/notifications/telegram')->assertRedirect('/profile/notifications');
        $url = session('profile.telegram_link.url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        return $query['start'];
    }

    private function update(string $token, int $chat = 123456): array
    {
        return ['update_id' => 1, 'message' => ['text' => '/start '.$token, 'chat' => ['id' => $chat, 'type' => 'private'], 'from' => ['id' => $chat, 'is_bot' => false, 'username' => 'test_actor']]];
    }

    private function webhook(array $update)
    {
        return $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::SECRET)->postJson('/webhooks/profile/telegram', $update);
    }

    public function test_deep_link_requires_private_callback_then_explicit_browser_confirmation(): void
    {
        [$user,,$brand] = $this->cabinet();
        $token = $this->start($user, $brand);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{48}$/D', $token);
        $row = DB::table('profile_telegram_links')->first();
        $this->assertNotSame($token, $row->token_hash);
        $this->webhook($this->update($token))->assertNoContent();
        $this->assertDatabaseHas('profile_telegram_links', ['user_id' => $user->id, 'token_hash' => null, 'chat_id' => null]);
        $this->assertDatabaseCount('notification_settings', 0);
        $this->get('/profile/notifications')->assertViewHas('page', fn ($p) => $p['props']['notificationSettings']['telegram']['pending'] && $p['props']['notificationSettings']['telegram']['link_available'] === false);
        $this->post('/profile/notifications/telegram/confirm')->assertRedirect('/profile/notifications');
        $this->assertDatabaseCount('notification_settings', 15);
        $setting = DB::table('notification_settings')->where('event_key', 'shell.member.invited')->where('channel', 'telegram')->first();
        $this->assertFalse((bool) $setting->enabled);
        $this->assertSame('123456', Crypt::decryptString($setting->destination));
        $this->put('/profile/notifications', ['changes' => [['event' => 'shell.member.invited', 'channel' => 'telegram', 'enabled' => true]]])->assertRedirect();
        $this->assertDatabaseHas('notification_settings', ['event_key' => 'shell.member.invited', 'channel' => 'telegram', 'enabled' => true]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'profile.telegram.connected', 'actor_id' => $user->id]);
        Http::assertNothingSent();
    }

    public function test_callback_checks_secret_before_lookup_and_refuses_groups_or_foreign_sender(): void
    {
        [$user,,$brand] = $this->cabinet();
        $token = $this->start($user, $brand);
        $this->postJson('/webhooks/profile/telegram', $this->update($token))->assertForbidden();
        $group = $this->update($token);
        $group['message']['chat']['type'] = 'group';
        $this->webhook($group)->assertNoContent();
        $foreign = $this->update($token);
        $foreign['message']['from']['id'] = 999999;
        $this->webhook($foreign)->assertNoContent();
        $this->assertDatabaseHas('profile_telegram_links', ['user_id' => $user->id, 'pending_chat_id' => null, 'chat_id' => null, 'token_hash' => hash('sha256', $token)]);
    }

    public function test_consumed_expired_and_reissued_tokens_cannot_rebind(): void
    {
        [$user,,$brand] = $this->cabinet();
        $old = $this->start($user, $brand);
        $new = $this->start($user, $brand);
        $this->webhook($this->update($old))->assertNoContent();
        $this->assertDatabaseHas('profile_telegram_links', ['user_id' => $user->id, 'pending_chat_id' => null]);
        $this->webhook($this->update($new, 123456))->assertNoContent();
        $this->webhook($this->update($new, 999999))->assertNoContent();
        $this->assertSame('123456', Crypt::decryptString(DB::table('profile_telegram_links')->value('pending_chat_id')));
        $this->travel(11)->minutes();
        $this->post('/profile/notifications/telegram/confirm')->assertSessionHasErrors('telegram');
        $this->assertDatabaseCount('notification_settings', 0);
    }

    public function test_same_chat_cannot_be_claimed_by_another_user_in_another_brand(): void
    {
        [$user,$org,$brand,$second] = $this->cabinet();
        [$other] = $this->member($org, 'admin');
        $token = $this->start($user, $brand);
        $this->webhook($this->update($token))->assertNoContent();
        $this->post('/profile/notifications/telegram/confirm')->assertRedirect();
        $token = $this->start($other, $second);
        $this->webhook($this->update($token))->assertNoContent();
        $this->post('/profile/notifications/telegram/confirm')->assertSessionHasErrors('telegram');
        $this->assertDatabaseCount('profile_telegram_accounts', 1);
        $this->assertDatabaseMissing('notification_settings', ['user_id' => $other->id]);
    }

    public function test_own_chat_can_be_used_for_two_brands_without_rebinding_to_another_chat(): void
    {
        [$user,,$brand,$second] = $this->cabinet();
        $token = $this->start($user, $brand);
        $this->webhook($this->update($token))->assertNoContent();
        $this->post('/profile/notifications/telegram/confirm')->assertRedirect();
        $token = $this->start($user, $second);
        $this->webhook($this->update($token))->assertNoContent();
        $this->post('/profile/notifications/telegram/confirm')->assertRedirect();
        $this->assertDatabaseCount('profile_telegram_accounts', 1);
        $this->assertDatabaseCount('notification_settings', 30);
    }

    public function test_disconnect_disables_runtime_destinations_and_replay(): void
    {
        [$user,,$brand] = $this->cabinet();
        $token = $this->start($user, $brand);
        $this->webhook($this->update($token))->assertNoContent();
        $this->post('/profile/notifications/telegram/confirm')->assertRedirect();
        $this->delete('/profile/notifications/telegram')->assertRedirect();
        $this->assertDatabaseCount('profile_telegram_links', 0);
        $this->assertDatabaseCount('profile_telegram_accounts', 0);
        $this->assertSame(0, DB::table('notification_settings')->whereNotNull('destination')->count());
        $this->assertSame(0, DB::table('notification_settings')->where('enabled', true)->count());
        $this->webhook($this->update($token))->assertNoContent();
        $this->assertDatabaseCount('profile_telegram_links', 0);
    }

    public function test_revoked_member_cannot_complete_pending_link(): void
    {
        [$user,,$brand,,$membership] = $this->cabinet();
        $token = $this->start($user, $brand);
        DB::table('memberships')->where('id', $membership->id)->update(['status' => 'disabled']);
        $this->webhook($this->update($token))->assertNoContent();
        $this->assertDatabaseHas('profile_telegram_links', ['user_id' => $user->id, 'pending_chat_id' => null]);
    }

    public function test_reissue_in_another_session_does_not_show_stale_deep_link(): void
    {
        [$user, $org, $brand, , $member] = $this->cabinet();
        $old = $this->start($user, $brand);
        $oldBrowser = Request::create('/profile/notifications', 'GET');
        $oldBrowser->setLaravelSession(clone app('session.store'));
        $new = $this->start($user, $brand);
        $this->assertNotSame($old, $new);
        $state = app(TelegramLinks::class)->state($oldBrowser, new TenantContext($org->id, $brand->id, $user->id, $member->id));
        $this->assertFalse($state['link_available']);
        $this->assertFalse($oldBrowser->session()->has('profile.telegram_link'));
        $page = $this->get('/profile/notifications')->assertOk();
        self::assertTrue($page->viewData('page')['props']['notificationSettings']['telegram']['link_available']);
        self::assertStringNotContainsString($new, $page->getContent());
        $this->get('/profile/notifications/telegram/link')->assertRedirect('https://t.me/souzpir_test_bot?start='.$new)->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_new_chat_can_replace_binding_in_archived_brand_only(): void
    {
        [$user, , $brand, $second] = $this->cabinet();
        $old = $this->start($user, $brand);
        $this->webhook($this->update($old, 123456))->assertNoContent();
        $this->post('/profile/notifications/telegram/confirm')->assertRedirect();
        DB::table('brands')->where('id', $brand->id)->update(['status' => 'archived']);
        $new = $this->start($user, $second);
        $this->webhook($this->update($new, 654321))->assertNoContent();
        $this->post('/profile/notifications/telegram/confirm')->assertRedirect();
        $this->assertDatabaseMissing('profile_telegram_links', ['user_id' => $user->id, 'brand_id' => $brand->id]);
        $this->assertSame(0, DB::table('notification_settings')->where('user_id', $user->id)->where('brand_id', $brand->id)->whereNotNull('destination')->count());
        $newRow = DB::table('profile_telegram_links')->where('user_id', $user->id)->where('brand_id', $second->id)->first();
        $this->assertSame('654321', Crypt::decryptString($newRow->chat_id));
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $user->id, 'action' => 'profile.telegram.inaccessible_binding_removed']);
    }

    public function test_new_chat_recovery_after_old_brand_grant_revoked_does_not_touch_other_users(): void
    {
        [$owner, $org, $brand, $second] = $this->cabinet();
        [$user, $member] = $this->member($org, 'member', [$brand->id, $second->id]);
        $ownerToken = $this->start($owner, $brand);
        $this->webhook($this->update($ownerToken, 999999))->assertNoContent();
        $this->post('/profile/notifications/telegram/confirm')->assertRedirect();
        $old = $this->start($user, $brand);
        $this->webhook($this->update($old, 123456))->assertNoContent();
        $this->post('/profile/notifications/telegram/confirm')->assertRedirect();
        DB::table('memberships')->where('id', $member->id)->update(['brand_ids' => json_encode([$second->id])]);
        $new = $this->start($user, $second);
        $this->webhook($this->update($new, 654321))->assertNoContent();
        $this->post('/profile/notifications/telegram/confirm')->assertRedirect();
        $this->assertDatabaseMissing('profile_telegram_links', ['user_id' => $user->id, 'brand_id' => $brand->id]);
        $ownerRow = DB::table('profile_telegram_links')->where('user_id', $owner->id)->first();
        $this->assertSame('999999', Crypt::decryptString($ownerRow->chat_id));
        $this->assertSame(15, DB::table('notification_settings')->where('user_id', $owner->id)->whereNotNull('destination')->count());
    }

    public function test_accessible_brand_binding_cannot_be_overwritten_by_new_chat_confirmation(): void
    {
        [$user, , $brand, $second] = $this->cabinet();
        $old = $this->start($user, $brand);
        $this->webhook($this->update($old, 123456))->assertNoContent();
        $this->post('/profile/notifications/telegram/confirm')->assertRedirect();
        $new = $this->start($user, $second);
        $this->webhook($this->update($new, 654321))->assertNoContent();
        $this->post('/profile/notifications/telegram/confirm')->assertSessionHasErrors('telegram');
        $oldRow = DB::table('profile_telegram_links')->where('user_id', $user->id)->where('brand_id', $brand->id)->first();
        $this->assertSame('123456', Crypt::decryptString($oldRow->chat_id));
        $this->assertDatabaseHas('profile_telegram_links', ['user_id' => $user->id, 'brand_id' => $second->id, 'chat_id' => null]);
    }

    public function test_telegram_capability_is_not_in_inertia_and_is_never_served_over_http_remote(): void
    {
        [$user, , $brand] = $this->cabinet();
        $token = $this->start($user, $brand);
        $page = $this->get('/profile/notifications')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        self::assertTrue($page->viewData('page')['props']['notificationSettings']['telegram']['link_available']);
        self::assertStringNotContainsString($token, $page->getContent());
        $this->withServerVariables(['SERVER_ADDR' => '203.0.113.1', 'REMOTE_ADDR' => '198.51.100.1']);
        $this->get('http://127.0.0.1/profile/notifications/telegram/link')->assertForbidden();
        $this->postJson('http://127.0.0.1/profile/notifications/telegram')->assertUnprocessable();
        $this->withServerVariables(['SERVER_ADDR' => '127.0.0.1', 'REMOTE_ADDR' => '127.0.0.1']);
        $this->webhook($this->update($token))->assertNoContent();
        $this->get('/profile/notifications/telegram/link')->assertNotFound();
    }
}
