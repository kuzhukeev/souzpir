<?php

namespace Tests\Feature\Seller\Settings\Policy;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\DatabaseKnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ClientFieldsE610Test extends TenancyTestCase
{
    public function test_fields_are_brand_scoped_saved_in_draft_published_and_restored(): void
    {
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        $draft = app(TenantContextRunner::class)->run($tenant, function () use ($tenant): array {
            $data = array_fill_keys(KnowledgeSnapshot::FILES, []);

            return app(KnowledgeRepository::class)->createDraft($tenant, $data);
        });
        $field = ['id' => 'outlet', 'name' => 'Формат точки', 'type' => 'select', 'options' => ['Павильон', 'Онлайн'], 'required' => true, 'stage' => 'need'];
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $invalid = $field;
        $invalid['id'] = 'email';
        $this->post('/seller/scenario/fields/draft', ['version' => 1, 'fields' => [$invalid]])->assertSessionHasErrors('fields.0.id');
        $invalid = $field;
        $invalid['options'] = [];
        $this->post('/seller/scenario/fields/draft', ['version' => 1, 'fields' => [$invalid]])->assertSessionHasErrors('fields.0.options');
        $this->post('/seller/scenario/fields/draft', ['version' => 1, 'fields' => [$field]])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/scenario/fields')->assertOk()->assertInertia(fn ($page) => $page->where('editor.version', 2)->where('editor.fields.0.name', 'Формат точки'));
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $draft, $field): void {
            $repo = app(KnowledgeRepository::class);
            self::assertEquals([$field], $repo->forRelease($tenant, $draft['id'], true)->policy()['client_fields']);
            $published = $repo->publish($tenant, $draft['id'], 2);
            self::assertEquals([$field], $published->policy()['client_fields']);
            $restored = app(DatabaseKnowledgeRepository::class)->restoreFile($tenant, $published->toArray(), []);
            self::assertEquals([$field], $repo->forRelease($tenant, $restored['id'], true)->policy()['client_fields']);
            self::assertSame($published->releaseId(), $repo->published($tenant)->releaseId());
        });
        $this->get('/seller/scenario/fields')->assertOk()->assertInertia(fn ($page) => $page->has('fields', 16)->where('fields.0.id', 'name'));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/scenario/fields')->assertOk()->assertInertia(fn ($page) => $page->where('editor.version', null)->where('editor.fields', []));
        $this->post('/seller/scenario/fields/draft', ['version' => 1, 'fields' => [$field]])->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/scenario/fields/draft', ['version' => 1, 'fields' => [$field]])->assertForbidden();
    }

    public function test_scenario_fields_table_shows_real_stage_label_and_select_options_matching_system_slot(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
            $data['policy'] = ['client_fields' => [['id' => 'outlet_type', 'name' => 'Тип точки', 'type' => 'select', 'options' => ['Павильон', 'ТЦ'], 'required' => true, 'stage' => 'need']]];
            $repo = app(KnowledgeRepository::class);
            $draft = $repo->createDraft($tenant, $data);
            $repo->publish($tenant, $draft['id'], 1);
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])
            ->get('/seller/scenario/fields')->assertOk()->assertInertia(fn ($page) => $page
            ->where('fields.4.id', 'outlet_type')
            ->where('fields.4.required_stage', 'need')
            ->where('fields.4.value_options', ['Павильон', 'ТЦ'])
            ->where('fields.0.required_stage', null)
            ->where('fields.0.value_options', []));
    }
}
