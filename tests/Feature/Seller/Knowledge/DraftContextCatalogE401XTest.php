<?php

namespace Tests\Feature\Seller\Knowledge;

use App\ProtectedOperations\DraftSimulation\FixtureRuntime;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Database\Seeders\Seller\Evaluation\SellerPersonaSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

/**
 * No dialogue() wording today reproduces the SP-E4-01R/S catalogs' own text (see
 * results SP-E4-01X), so this drives the real public FixtureRuntime::checkPartial
 * with a real seeded persona and the catalog's own context, through the same
 * request a real scenario run produces — no reflection into a private method.
 */
final class DraftContextCatalogE401XTest extends TenancyTestCase
{
    public function test_matches_the_s_catalog_handoff_event_for_a_real_seeded_persona(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(SellerPersonaSeeder::class)->seedForBrand($tenant);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $id = DB::table('knowledge_releases')->where('brand_id', $brand->id)->where('status', 'draft')->value('id');
        // A real dialogue run binds the tenant-checked request this test reuses below.
        $this->post('/seller/knowledge/'.$id.'/scenarios')->assertRedirect();
        /** @var Request $request */
        $request = app('request');
        $history = [['role' => 'client', 'text' => 'Здравствуйте.'], ['role' => 'agent', 'text' => 'Здравствуйте! Чем могу помочь?']];
        $events = [['type' => 'handoff', 'reason' => 'requested_by_client']];
        $runtime = app(FixtureRuntime::class);

        // The HTTP kernel forgets the scoped TenantContext binding once the dialogue
        // request above terminates; restore it the same way a job would (TenantContextRunner).
        [$matched, $withoutEvent, $withoutHistory] = app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $id, $request, $runtime, $history, $events): array {
            $draft = app(KnowledgeRepository::class)->forRelease($tenant, $id, true);
            $otherHistory = [['role' => 'client', 'text' => 'Другой контекст.']];

            return [
                $runtime->checkPartial($request, $draft, 'Позовите человека.', 'Передаю диалог менеджеру.', ['handoff_fast'], 'wants_human', $history, $events),
                $runtime->checkPartial($request, $draft, 'Позовите человека.', 'Передаю диалог менеджеру.', ['handoff_fast'], 'wants_human', $history, []),
                $runtime->checkPartial($request, $draft, 'Позовите человека.', 'Передаю диалог менеджеру.', ['handoff_fast'], 'wants_human', $otherHistory, $events),
            ];
        });
        self::assertSame(['handoff_fast' => true], $matched);
        self::assertSame(['handoff_fast' => null], $withoutEvent);
        self::assertSame(['handoff_fast' => null], $withoutHistory);
    }
}
