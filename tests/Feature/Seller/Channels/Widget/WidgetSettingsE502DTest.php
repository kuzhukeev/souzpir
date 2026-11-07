<?php

namespace Tests\Feature\Seller\Channels\Widget;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\SellerRuntime\Turns\ProtectedFollowupFixtures;
use Tests\Feature\Tenancy\TenancyTestCase;

final class WidgetSettingsE502DTest extends TenancyTestCase
{
    use ProtectedFollowupFixtures;

    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function payload(): array
    {
        return ['enabled' => true, 'origins' => ['https://example.test'], 'appearance' => ['title' => 'Связаться', 'color' => '#176b42', 'position' => 'left', 'offset_side' => 12, 'offset_bottom' => 18, 'shape' => 'round', 'icon' => 'bot', 'window_title' => 'Чат', 'theme' => 'dark', 'hide_mobile' => true],
            'behavior' => ['delay_seconds' => 10, 'scroll_percent' => 50, 'invitation' => 'Здравствуйте', 'exit_intent' => true, 'page_mask' => '/catalog/*', 'repeat_hours' => 24, 'collect_contact' => true, 'contact_mode' => 'soft', 'consent_text' => 'Согласен на обработку', 'consent_url' => 'https://example.test/privacy', 'work_days' => [0, 2, 4], 'work_start' => '09:00', 'work_end' => '18:00', 'offhours_text' => 'Ответим позже', 'excluded_paths' => ['/privacy', '/private/*'], 'max_messages' => 20]];
    }

    public function test_tabs_save_and_isolate_settings_by_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            $this->enable($org->id, $item->id);
        }
        DB::table('brands')->where('organization_id', $org->id)->where('id', $brand->id)->update(['website' => 'https://example.test/catalog']);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        foreach (['install', 'look', 'behavior'] as $tab) {
            $this->get('/seller/channels/widget/'.$tab)->assertOk()->assertInertia(fn ($page) => $page->where('activeTab', $tab)->where('brandOrigin', 'https://example.test'));
        }
        $this->post('/seller/channels/widget', $this->payload())->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/channels/widget/behavior')->assertOk()->assertInertia(fn ($page) => $page->where('settings.behavior.work_days', [0, 2, 4])->where('settings.behavior.excluded_paths', ['/privacy', '/private/*'])->where('settings.behavior.max_messages', 20)->where('settings.behavior.contact_mode', 'soft')->where('settings.behavior.consent_text', 'Согласен на обработку')->where('settings.appearance.offset_side', 12)->where('settings.appearance.theme', 'dark')->where('settings.appearance.hide_mobile', true));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/channels/widget/behavior')->assertOk()->assertInertia(fn ($page) => $page->where('settings.enabled', false)->has('settings.origins', 0));
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_correspondence_status_reflects_driver_and_live_draft_flag(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/channels/widget')->assertOk()
            ->assertInertia(fn ($page) => $page->where('correspondence.live', false)->where('correspondence.liveDraftEnabled', false));

        config(['runtime.ai_driver' => 'openrouter', 'features.LV-01.defaults.enabled' => true]);
        $this->get('/seller/channels/widget')->assertOk()
            ->assertInertia(fn ($page) => $page->where('correspondence.live', true)->where('correspondence.liveDraftEnabled', true));

        config(['features.LV-01.defaults.enabled' => false]);
        $this->get('/seller/channels/widget')->assertOk()
            ->assertInertia(fn ($page) => $page->where('correspondence.live', true)->where('correspondence.liveDraftEnabled', false));
    }

    public function test_message_limit_hands_visitor_off_to_manager_while_messages_still_reach_manager(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->seedProtected(new TenantContext($org->id, $brand->id, $owner->id, $membership->id));
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $data = $this->payload();
        $data['behavior']['max_messages'] = 1;
        $this->post('/seller/channels/widget', $data)->assertRedirect()->assertSessionHasNoErrors();
        $key = DB::table('widget_settings')->where('brand_id', $brand->id)->value('public_key');

        config(['runtime.ai_driver' => 'fake']);
        $this->withHeader('Origin', 'https://example.test');
        $session = $this->postJson('/widget/session?key='.$key)->assertCreated()->json('token');
        $url = '/widget/message?key='.$key;

        $first = $this->postJson($url, ['token' => $session, 'text' => 'Привет', 'idempotency_key' => 'm1', 'page' => 'home'])->assertCreated();
        self::assertIsString($first->json('reply'));
        self::assertArrayNotHasKey('notice', $first->json());

        $second = $this->postJson($url, ['token' => $session, 'text' => 'Ещё вопрос', 'idempotency_key' => 'm2', 'page' => 'home'])->assertCreated();
        self::assertArrayNotHasKey('reply', $second->json());
        self::assertSame('Вы отправили максимум сообщений в этом чате. Дальше вам ответит менеджер.', $second->json('notice'));

        self::assertSame(2, DB::table('messages')->where('role', 'client')->count());
        $this->assertDatabaseHas('messages', ['text' => 'Привет', 'role' => 'client']);
        $this->assertDatabaseHas('messages', ['text' => 'Ещё вопрос', 'role' => 'client']);
    }

    public function test_installation_check_requires_enabled_widget_with_an_origin(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        Http::fake();
        $this->post('/seller/channels/widget/check')->assertRedirect()
            ->assertSessionHas('status', 'Сначала включите виджет и добавьте адрес сайта.');
        Http::assertNothingSent();
    }

    public function test_invalid_settings_and_viewer_are_rejected(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $data = $this->payload();
        $data['behavior']['consent_text'] = str_repeat('x', 301);
        $data['behavior']['max_messages'] = 101;
        $this->post('/seller/channels/widget', $data)->assertSessionHasErrors(['behavior.consent_text', 'behavior.max_messages']);
        $data = $this->payload();
        $data['behavior']['excluded_paths'] = ['https://other.test/'];
        $this->post('/seller/channels/widget', $data)->assertSessionHasErrors('behavior.excluded_paths.0');
        $data = $this->payload();
        $data['behavior']['collect_contact'] = false;
        $this->post('/seller/channels/widget', $data)->assertSessionHasErrors('behavior.contact_mode');
        $data = $this->payload();
        $data['behavior']['work_start'] = null;
        $this->post('/seller/channels/widget', $data)->assertSessionHasErrors('behavior.work_start');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/channels/widget/behavior')->assertForbidden();
        $this->post('/seller/channels/widget', $this->payload())->assertForbidden();
    }
}
