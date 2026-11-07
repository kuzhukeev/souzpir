<?php

namespace Tests\Feature\Seller\Settings\Policy;

use App\Seller\Engine\LeadState;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class PrototypeFieldsE610ETest extends TenancyTestCase
{
    private function draft(TenantContext $tenant, string $company): array
    {
        return app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $company): array {
            $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
            $data['persona'] = ['company' => $company];

            return app(KnowledgeRepository::class)->createDraft($tenant, $data);
        });
    }

    public function test_confirmed_prototype_brand_prefills_draft_with_wholesale_fields(): void
    {
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        $this->draft($tenant, 'Союз Пир');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/scenario/fields')->assertOk()->assertInertia(fn ($page) => $page
            ->where('editor.fields.0.id', 'city')
            ->where('editor.fields.0.name', LeadState::slotLabels()['city'])
            ->missing('editor.fields.13'));

        $otherTenant = new TenantContext($org->id, $other->id, $owner->id, $membership->id);
        $this->draft($otherTenant, 'Авторемонт');
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/scenario/fields')->assertOk()->assertInertia(fn ($page) => $page->where('editor.fields', []));
    }
}
