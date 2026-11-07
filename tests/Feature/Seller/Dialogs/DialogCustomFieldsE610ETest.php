<?php

namespace Tests\Feature\Seller\Dialogs;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DialogCustomFieldsE610ETest extends TenancyTestCase
{
    private function conversation(string $organization, string $brand): string
    {
        $id = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $id, 'organization_id' => $organization, 'brand_id' => $brand, 'state' => json_encode(['slots' => ['car_model' => 'Toyota Camry']]), 'mode' => 'bot', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function test_published_additional_field_is_shown_and_editable_in_the_dialog_card(): void
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
        $dialog = $this->conversation($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/dialogs/'.$dialog)->assertOk()->assertInertia(fn ($page) => $page
            ->where('dialog.slots', fn ($slots) => collect($slots)->firstWhere('key', 'car_model')['label'] === 'Марка и модель'
                && collect($slots)->firstWhere('key', 'car_model')['value'] === 'Toyota Camry'));
        $this->put('/seller/dialogs/'.$dialog.'/profile', ['slots' => ['car_model' => 'Honda Civic']])->assertRedirect();
        self::assertSame('Honda Civic', json_decode(DB::table('conversations')->where('id', $dialog)->value('state'), true)['slots']['car_model']);
        $this->putJson('/seller/dialogs/'.$dialog.'/profile', ['slots' => ['not_a_field' => 'x']])->assertUnprocessable();
    }
}
