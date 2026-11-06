<?php

namespace Tests\Feature\Seller\Knowledge\Prohibitions;

use App\Seller\Engine\DeterministicGuard;
use App\Seller\Engine\Goal;
use App\Seller\Engine\LeadState;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Seller\Knowledge\Prohibitions\GuardHitRecorder;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ProhibitionsE405DTest extends TenancyTestCase
{
    public function test_counts_real_guard_matches_per_brand_and_last_30_days(): void
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
        $data['guard_rules'] = ['forbidden_promises' => [
            ['id' => 'one', 'description' => 'Кредит', 'severity' => 'block', 'patterns' => ['кредит'], 'allow_if' => ['без кредита']],
            ['id' => 'two', 'description' => 'Доставка', 'severity' => 'warn', 'patterns' => ['доставка']],
            ['id' => 'disabled', 'description' => 'Отключено', 'enabled' => false, 'patterns' => ['кредит']],
        ]];
        app(TenantContextRunner::class)->run($tenant, fn () => app(KnowledgeRepository::class)->createDraft($tenant, $data));
        $snapshot = new KnowledgeSnapshot($data, (string) Str::ulid(), 'test');
        $guard = new DeterministicGuard($snapshot);
        $recorder = app(GuardHitRecorder::class);
        $text = 'кредит и доставка';
        $result = $guard->check($text, new Goal('answer_only'), new LeadState);
        $responder = (string) Str::ulid();
        $recorder->record($tenant, $responder, $snapshot, $text, $result);
        $recorder->record($tenant, $responder, $snapshot, $text, $result);
        $allowed = 'без кредита';
        $recorder->record($tenant, (string) Str::ulid(), $snapshot, $allowed, $guard->check($allowed, new Goal('answer_only'), new LeadState));
        self::assertSame(2, DB::table('prohibition_guard_hits')->count());
        DB::table('prohibition_guard_hits')->insert(['organization_id' => $org->id, 'brand_id' => $brand->id, 'responder_id' => (string) Str::ulid(), 'rule_id' => 'one', 'created_at' => now()->subDays(31)]);
        DB::table('prohibition_guard_hits')->insert(['organization_id' => $org->id, 'brand_id' => $other->id, 'responder_id' => (string) Str::ulid(), 'rule_id' => 'one', 'created_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/knowledge/prohibitions')->assertOk()->assertInertia(fn ($page) => $page->where('rules.0.hits30Days', 1)->where('rules.1.hits30Days', 1)->where('rules.2.hits30Days', 0));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/knowledge/prohibitions')->assertForbidden();
    }
}
