<?php

namespace Tests\Feature\Seller\Customers;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class CustomFieldLabelsE610ETest extends TenancyTestCase
{
    public function test_published_additional_field_is_labelled_and_separated_from_system_slots(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
            $data['policy'] = ['client_fields' => [['id' => 'car_model', 'name' => 'Марка и модель', 'type' => 'text', 'options' => [], 'required' => true, 'stage' => 'need']]];
            $repo = app(KnowledgeRepository::class);
            $draft = $repo->createDraft($tenant, $data);
            $repo->publish($tenant, $draft['id'], 1);
        });
        $contact = (string) Str::ulid();
        $conversation = (string) Str::ulid();
        DB::table('contacts')->insert(['id' => $contact, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'name' => 'Клиент А', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conversations')->insert(['id' => $conversation, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'contact_id' => $contact,
            'state' => json_encode(['slots' => ['city' => 'Тверь', 'car_model' => 'Toyota Camry']]), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/customers/'.$contact)->assertOk()->assertInertia(fn ($page) => $page
            ->where('customFieldLabels.car_model', 'Марка и модель')
            ->where('slots.city', 'Тверь')->where('slots.car_model', 'Toyota Camry'));
    }
}
