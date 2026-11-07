<?php

namespace Tests\Feature\Shell\Organizations;

use Illuminate\Support\Carbon;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ModuleRetentionE104FTest extends TenancyTestCase
{
    public function test_disabled_module_exposes_review_date_for_target_brand(): void
    {
        Carbon::setTestNow('2026-01-15 12:00:00+00');
        try {
            [$owner, $org, $brand, $other] = $this->cabinet();
            [, , $foreign] = $this->cabinet(false);
            $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
            $this->put('/brands/'.$brand->id.'/modules/vision', ['enabled' => false])->assertSessionHasNoErrors();
            $this->get('/brands/'.$brand->id.'/modules')->assertOk()->assertInertia(fn ($page) => $page
                ->where('items.vision.disabled_at', fn ($date) => str_starts_with($date, '2026-01-15'))
                ->where('items.vision.retention_review_at', fn ($date) => str_starts_with($date, '2026-04-15')));
            $this->get('/brands/'.$other->id.'/modules')->assertOk()->assertInertia(fn ($page) => $page->where('items.vision.disabled_at', null));
            $this->get('/brands/'.$foreign->id.'/modules')->assertNotFound();
            $this->put('/brands/'.$brand->id.'/modules/unknown', ['enabled' => true])->assertNotFound();
            $this->put('/brands/'.$brand->id.'/modules/vision', ['enabled' => 'invalid'])->assertSessionHasErrors('enabled');
            [$viewer] = $this->member($org, 'viewer', [$brand->id]);
            $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/brands/'.$brand->id.'/modules')->assertForbidden();
        } finally {
            Carbon::setTestNow();
        }
    }
}
