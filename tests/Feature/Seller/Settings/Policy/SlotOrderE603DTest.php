<?php

namespace Tests\Feature\Seller\Settings\Policy;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SlotOrderE603DTest extends TenancyTestCase
{
    public function test_saving_order_is_scoped_validated_and_authorized(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $member = DB::table('memberships')->where('organization_id', $org->id)->where('user_id', $owner->id)->first();
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        $draft = app(TenantContextRunner::class)->run($tenant, function () use ($tenant): array {
            $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
            $data['policy'] = ['slot_order' => ['unknown' => ['city', 'purpose']]];

            return app(KnowledgeRepository::class)->createDraft($tenant, $data);
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/settings/slot-order', ['version' => 1, 'slot_order' => ['unknown' => ['purpose', 'city']], 'max_consecutive_questions' => 3])->assertRedirect()->assertSessionHasNoErrors();
        $policy = app(TenantContextRunner::class)->run($tenant, fn () => app(KnowledgeRepository::class)->forRelease($tenant, $draft['id'], true)->policy());
        self::assertSame(['purpose', 'city'], $policy['slot_order']['unknown']);
        self::assertSame(3, $policy['limits']['max_consecutive_questions']);
        $this->post('/seller/settings/slot-order', ['version' => 2, 'slot_order' => ['unknown' => ['purpose', 'purpose']], 'max_consecutive_questions' => 2])->assertSessionHasErrors('slot_order');
        $this->post('/seller/settings/slot-order', ['version' => 2, 'slot_order' => ['unknown' => ['city', 'purpose']], 'max_consecutive_questions' => 6])->assertSessionHasErrors('max_consecutive_questions');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/settings/slot-order', ['version' => 2, 'slot_order' => ['unknown' => ['city', 'purpose']], 'max_consecutive_questions' => 2])->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->post('/seller/settings/slot-order', ['version' => 1, 'slot_order' => ['unknown' => ['city', 'purpose']], 'max_consecutive_questions' => 2])->assertNotFound();
    }
}
