<?php

namespace Tests\Feature\Seller\Settings\Policy;

use App\Seller\Followups\FollowupPolicy;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class PolicySettingsTest extends TenancyTestCase
{
    public function test_followup_statistics_count_replies_per_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            $this->enable($org->id, $item->id);
            $dialog = (string) Str::ulid();
            DB::table('conversations')->insert(['id' => $dialog, 'organization_id' => $org->id, 'brand_id' => $item->id, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($item->id === $brand->id ? ['agent', 'client', 'agent', 'agent', 'client'] : ['agent'] as $index => $role) {
                DB::table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'conversation_id' => $dialog,
                    'role' => $role, 'text' => 'test', 'meta' => json_encode($role === 'agent' ? ['goal' => ['kind' => 'followup_nudge']] : []),
                    'created_at' => now()->addMinutes($index), 'updated_at' => now()]);
            }
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/settings/policy')
            ->assertOk()->assertInertia(fn ($page) => $page->missing('followupStats'));
        $this->get('/seller/settings/policy', ['X-Inertia-Partial-Component' => 'Seller/Settings/Policy/Index', 'X-Inertia-Partial-Data' => 'followupStats'])
            ->assertOk()->assertInertia(fn ($page) => $page->where('followupStats.sent', 3)->where('followupStats.replies', [1, 1]));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/settings/policy', ['X-Inertia-Partial-Component' => 'Seller/Settings/Policy/Index', 'X-Inertia-Partial-Data' => 'followupStats'])
            ->assertOk()->assertInertia(fn ($page) => $page->where('followupStats.sent', 1)->where('followupStats.replies', [0, 0]));
    }

    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_save_followup_settings_affects_runtime_and_is_isolated(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/settings/policy')->assertOk()->assertInertia(fn ($page) => $page->where('policy', null)->where('followup.first_hours', 20)->where('followupTimezone', $brand->fresh()->timezone)->missing('followupStats'));
        $input = ['enabled' => true, 'first_hours' => 4, 'second_hours' => 8, 'max_auto' => 2, 'window' => ['start' => '09:00', 'end' => '18:00'], 'texts' => ['Напоминание', 'Второй текст'], 'agreed_text' => 'Договорились'];
        $this->post('/seller/settings/followups', $input)->assertRedirect();
        $settings = json_decode(DB::table('brand_seller_settings')->where('brand_id', $brand->id)->value('followup'), true);
        $this->assertSame(['Напоминание', 'Второй текст'], $settings['texts']);
        $now = CarbonImmutable::parse('2026-11-02 12:00 UTC');
        $conversation = ['stage' => 'need', 'last_client_message_at' => $now->subHours(6)->toIso8601String(), 'state' => []];
        $policy = new FollowupPolicy;
        $this->assertNotNull($policy->due($conversation, $settings, $now->subHours(5), $now, 'UTC'));
        $this->assertNull($policy->due($conversation, $settings, $now->subHours(3), $now, 'UTC'));
        $this->assertNull($policy->due($conversation, $settings, $now->subHours(5), $now->setTime(19, 0), 'UTC'));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/settings/policy')->assertOk()->assertInertia(fn ($page) => $page->where('followup.first_hours', 20));
    }

    public function test_input_validation_and_access(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $input = ['enabled' => true, 'first_hours' => 0, 'second_hours' => null, 'max_auto' => 2, 'window' => null, 'texts' => [], 'agreed_text' => ''];
        $this->post('/seller/settings/followups', $input)->assertSessionHasErrors('first_hours');
        $input['first_hours'] = 20;
        $input['window'] = ['start' => '09:00', 'end' => '09:00'];
        $this->post('/seller/settings/followups', $input)->assertSessionHasErrors('window.end');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/settings/policy')->assertOk()->assertInertia(fn ($page) => $page->where('canWrite', false));
        $input['window'] = null;
        $this->post('/seller/settings/followups', $input)->assertForbidden();
    }

    public function test_published_policy_is_read_from_snapshot(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $membership = DB::table('memberships')->where('user_id', $owner->id)->where('organization_id', $org->id)->first();
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
            $data['policy'] = ['slot_order' => ['unknown' => ['city', 'purpose']], 'stages' => ['open' => ['next' => 'need']], 'limits' => ['off_topic_streak_silent' => 4]];
            $repo = app(KnowledgeRepository::class);
            $draft = $repo->createDraft($tenant, $data);
            $repo->publish($tenant, $draft['id'], 1);
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/settings/policy')->assertOk()->assertInertia(fn ($page) => $page->where('policy.slot_order.unknown.0', 'city')->where('policy.limits.off_topic_streak_silent', 4));
    }
}
