<?php

namespace Tests\Feature\Seller\Analytics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class AnalyticsCsvE704HTest extends TenancyTestCase
{
    public function test_csv_includes_report_metrics_and_honours_brand_and_rights(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $id = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'assigned_user_id' => $owner->id, 'stage' => 'done', 'state' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $id, 'role' => 'agent', 'text' => 'Ответ', 'meta' => '{"intent":"delivery"}', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $csv = $this->get('/seller/analytics/export.csv')->assertOk()->streamedContent();
        self::assertStringContainsString('Диалоги;1', $csv);
        self::assertStringContainsString('Сообщения;1', $csv);
        self::assertStringContainsString('Темы / delivery', $csv);
        self::assertStringContainsString('Менеджеры / 0 / assigned', $csv);
        self::assertStringContainsString('Среднее время до контакта', $csv);
        $this->get('/seller/analytics/export.csv?from=bad')->assertSessionHasErrors('from');
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        self::assertStringContainsString('Диалоги;0', $this->get('/seller/analytics/export.csv')->assertOk()->streamedContent());
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        self::assertStringNotContainsString('Менеджеры /', $this->get('/seller/analytics/export.csv')->assertOk()->streamedContent());
        $this->get('/seller/analytics/export.csv?manager='.$owner->id)->assertForbidden();
    }
}
