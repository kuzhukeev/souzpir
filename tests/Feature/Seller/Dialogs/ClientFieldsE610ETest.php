<?php

namespace Tests\Feature\Seller\Dialogs;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Seller\Knowledge\Fixtures\PrototypeKnowledge;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ClientFieldsE610ETest extends TenancyTestCase
{
    public function test_dialog_shows_and_saves_only_its_published_fields(): void
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id,
                'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $prototype = new PrototypeKnowledge(base_path('tests/Feature/Seller/Knowledge/Fixtures/data'), 0);
        $data = [];
        foreach (PrototypeKnowledge::FILES as $file) {
            $data[$file] = $prototype->all($file);
        }
        $data['policy']['client_fields'] = [
            ['id' => 'car_year', 'name' => 'Год выпуска', 'type' => 'number', 'options' => [], 'required' => true, 'stage' => 'need'],
            ['id' => 'service_kind', 'name' => 'Услуга', 'type' => 'select', 'options' => ['Диагностика', 'Ремонт'], 'required' => false, 'stage' => null],
        ];
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $data): void {
            $repo = app(KnowledgeRepository::class);
            $draft = $repo->createDraft($tenant, $data);
            $repo->publish($tenant, $draft['id'], 1);
        });
        $contact = (string) Str::ulid();
        DB::table('contacts')->insert(['id' => $contact, 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'name' => 'Клиент', 'created_at' => now(), 'updated_at' => now()]);
        $id = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'contact_id' => $contact, 'state' => json_encode(['slots' => ['car_year' => 2020]]), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/dialogs/'.$id)->assertOk()->assertInertia(fn ($page) => $page
            ->where('dialog.slots.16.label', 'Год выпуска')->where('dialog.slots.16.value', '2020')->where('dialog.slots.16.type', 'number')
            ->where('dialog.slots.17.label', 'Услуга')->where('dialog.slots.17.type', 'select')->where('dialog.slots.17.options.1', 'Ремонт'));
        $this->get('/seller/customers/'.$contact)->assertOk()->assertInertia(fn ($page) => $page
            ->where('customFieldLabels.car_year', 'Год выпуска')->where('slots.car_year', 2020));
        $this->put('/seller/dialogs/'.$id.'/profile', ['slots' => ['car_year' => '2022', 'service_kind' => 'Ремонт']])->assertRedirect()->assertSessionHasNoErrors();
        $state = json_decode(DB::table('conversations')->where('id', $id)->value('state'), true);
        self::assertSame(2022, $state['slots']['car_year']);
        self::assertSame('Ремонт', $state['slots']['service_kind']);
        $this->putJson('/seller/dialogs/'.$id.'/profile', ['slots' => ['car_year' => 'нет']])->assertUnprocessable();
        $this->putJson('/seller/dialogs/'.$id.'/profile', ['slots' => ['service_kind' => 'Другое']])->assertUnprocessable();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/seller/dialogs/'.$id)->assertNotFound();
    }
}
