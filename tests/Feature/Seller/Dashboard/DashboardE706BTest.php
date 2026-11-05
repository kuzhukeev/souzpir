<?php

namespace Tests\Feature\Seller\Dashboard;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DashboardE706BTest extends TenancyTestCase
{
    public function test_topics_use_published_names_and_are_brand_scoped(): void
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
            $data['intents'] = [['id' => 'ask_price', 'title' => 'Цена и условия']];
            $repo = app(KnowledgeRepository::class);
            $draft = $repo->createDraft($tenant, $data);
            $repo->publish($tenant, $draft['id'], 1);
        });
        $conversation = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $conversation, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'stage' => 'open', 'state' => '{}', 'mode' => 'bot', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $conversation, 'role' => 'client', 'text' => 'Цена?', 'meta' => json_encode(['intent' => 'ask_price']), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller')->assertOk()->assertInertia(fn ($page) => $page->where('dashboard.topics.Цена и условия', 1));
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/seller')->assertOk()->assertInertia(fn ($page) => $page->where('dashboard.topics', []));
        $this->get('/seller?days=8')->assertSessionHasErrors('days');
    }

    public function test_non_database_query_failure_shows_load_error(): void
    {
        $this->get('/seller')->assertRedirect();
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('brands')->where('id', $brand->id)->update(['timezone' => 'Invalid/Timezone']);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller')->assertOk()->assertInertia(fn ($page) => $page->where('dashboard', null)->where('initialLoadError', true));
    }
}
