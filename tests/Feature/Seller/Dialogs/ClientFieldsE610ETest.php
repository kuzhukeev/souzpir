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
            ['id' => 'contact_time', 'name' => 'Время связи', 'type' => 'text', 'options' => [], 'required' => true, 'stage' => 'handoff'],
            ['id' => 'city', 'name' => 'Филиал', 'type' => 'select', 'options' => ['Москва', 'Казань'], 'required' => false, 'stage' => null],
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
            ->where('dialog.slots.1.label', 'Филиал')->where('dialog.slots.1.type', 'select')
            ->where('dialog.slots.16.label', 'Год выпуска')->where('dialog.slots.16.value', '2020')->where('dialog.slots.16.type', 'number')
            ->where('dialog.slots.17.label', 'Услуга')->where('dialog.slots.17.type', 'select')->where('dialog.slots.17.options.1', 'Ремонт'));
        $this->get('/seller/customers/'.$contact)->assertOk()->assertInertia(fn ($page) => $page
            ->where('customFieldLabels.car_year', 'Год выпуска')->where('slots.car_year', 2020));
        $this->put('/seller/dialogs/'.$id.'/profile', ['slots' => ['car_year' => '2022', 'service_kind' => 'Ремонт']])->assertRedirect()->assertSessionHasNoErrors();
        $state = json_decode(DB::table('conversations')->where('id', $id)->value('state'), true);
        self::assertSame(2022, $state['slots']['car_year']);
        self::assertSame('Ремонт', $state['slots']['service_kind']);
        $this->get('/seller/customers?client_field=car_year&client_value=2022')->assertOk()->assertInertia(fn ($page) => $page
            ->has('customers', 1)->where('customers.0.id', $contact)->where('clientFields.0.name', 'Год выпуска'));
        $this->get('/seller/customers?client_field=car_year&client_value=2020')->assertOk()->assertInertia(fn ($page) => $page->has('customers', 0));
        $this->get('/seller/customers?client_field=unknown&client_value=x')->assertUnprocessable();
        $this->putJson('/seller/dialogs/'.$id.'/profile', ['slots' => ['car_year' => 'нет']])->assertUnprocessable();
        $this->putJson('/seller/dialogs/'.$id.'/profile', ['slots' => ['service_kind' => 'Другое']])->assertUnprocessable();
        $this->putJson('/seller/dialogs/'.$id.'/profile', ['slots' => ['city' => 'Другое']])->assertUnprocessable();
        $this->put('/seller/dialogs/'.$id.'/profile', ['slots' => ['city' => 'Казань']])->assertSessionHasNoErrors();
        $this->post('/seller/dialogs/'.$id.'/transfer', ['assigned_user_id' => $owner->id])->assertSessionHasErrors('assigned_user_id');
        $this->assertDatabaseHas('conversations', ['id' => $id, 'assigned_user_id' => null]);
        $this->put('/seller/dialogs/'.$id.'/profile', ['slots' => ['contact_time' => 'Вечером']])->assertSessionHasNoErrors();
        $this->post('/seller/dialogs/'.$id.'/transfer', ['assigned_user_id' => $owner->id])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('conversations', ['id' => $id, 'assigned_user_id' => $owner->id]);
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/seller/dialogs/'.$id)->assertNotFound();
        $this->get('/seller/customers?client_field=car_year&client_value=2022')->assertUnprocessable();
    }
}
