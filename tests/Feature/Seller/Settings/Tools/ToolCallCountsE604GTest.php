<?php

namespace Tests\Feature\Seller\Settings\Tools;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ToolCallCountsE604GTest extends TenancyTestCase
{
    public function test_only_current_brand_handoff_tasks_in_the_last_30_days_are_counted(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $conversation = fn (string $brandId) => DB::table('conversations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brandId,
            'created_at' => now(), 'updated_at' => now()]);
        $task = function (string $brandId, string $type, $date) use ($org): void {
            $conversationId = DB::table('conversations')->where('organization_id', $org->id)->where('brand_id', $brandId)->value('id');
            DB::table('seller_tasks')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brandId,
                'conversation_id' => $conversationId, 'type' => $type, 'payload' => '{}', 'status' => 'new', 'priority' => 'normal',
                'created_at' => $date, 'updated_at' => $date]);
        };
        $conversation($brand->id);
        $conversation($other->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/scenario/tools')->assertOk()->assertInertia(fn ($page) => $page->where('callCounts.escalate', null));
        $task($brand->id, 'handoff', now()->subDays(2));
        $task($brand->id, 'handoff', now()->subDays(31));
        $task($brand->id, 'reminder', now());
        $task($other->id, 'handoff', now());
        $task($other->id, 'handoff', now());
        $this->get('/seller/scenario/tools')->assertOk()->assertInertia(fn ($page) => $page->where('callCounts.escalate', 1)->where('toolsLoadError', false));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/scenario/tools')->assertOk()->assertInertia(fn ($page) => $page->where('callCounts.escalate', 2));
        Http::assertNothingSent();
    }

    public function test_tools_screen_reports_load_error_instead_of_breaking_when_counts_query_fails(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        DB::listen(function ($query): void {
            if (str_contains($query->sql, 'select') && str_contains($query->sql, 'audit_logs')) {
                throw new QueryException('pgsql', $query->sql, [], new \RuntimeException('fixture error'));
            }
        });
        $this->get('/seller/scenario/tools')->assertOk()->assertInertia(fn ($page) => $page->where('toolsLoadError', true)->where('callCounts', []));
        Http::assertNothingSent();
    }
}
