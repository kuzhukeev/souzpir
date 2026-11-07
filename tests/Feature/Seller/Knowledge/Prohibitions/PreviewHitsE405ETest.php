<?php

namespace Tests\Feature\Seller\Knowledge\Prohibitions;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class PreviewHitsE405ETest extends TenancyTestCase
{
    public function test_draft_and_sandbox_checks_do_not_record_hits(): void
    {
        [$owner, $org, $brand, , $member] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        $draft = app(TenantContextRunner::class)->run($tenant, function () use ($tenant): array {
            $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
            $data['guard_rules'] = ['forbidden_promises' => [['id' => 'draft_only', 'description' => 'Отсрочка', 'patterns' => ['отсрочк'], 'severity' => 'block']]];

            return app(KnowledgeRepository::class)->createDraft($tenant, $data);
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->postJson('/seller/knowledge/prohibitions/check', ['text' => 'Дадим отсрочку'])->assertOk()->assertJsonCount(1, 'issues');
        $this->postJson('/seller/knowledge/sandbox/check', ['release' => $draft['id'], 'text' => 'Дадим отсрочку'])->assertOk()->assertJsonCount(1, 'issues');
        self::assertSame(0, DB::table('prohibition_guard_hits')->count());
    }
}
