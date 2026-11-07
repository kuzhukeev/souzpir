<?php

namespace Tests\Feature\Shell\Context;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Tenancy\TenancyTestCase;

final class OrganizationSvgE102HTest extends TenancyTestCase
{
    public function test_private_svg_is_scoped_and_invalid_paths_are_rejected(): void
    {
        Storage::fake('local');
        [$owner, $org, $brand] = $this->cabinet();
        [$foreign, $foreignOrg, $foreignBrand] = $this->cabinet(false);
        $path = 'organizations/'.$org->id.'/onboarding/logos/'.str_repeat('a', 32).'.svg';
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>';
        Storage::disk('local')->put($path, $svg);
        foreach ([[$org, $brand, $path], [$foreignOrg, $foreignBrand, $path]] as [$organization, $item, $logo]) {
            DB::table('onboarding_progress')->insert(['organization_id' => $organization->id, 'brand_id' => $item->id, 'draft' => '{}', 'step' => 4, 'completed_at' => now(), 'logo_path' => $logo, 'created_at' => now(), 'updated_at' => now()]);
        }
        $url = '/context/organization/logo';
        $this->get($url)->assertRedirect();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/context/organization')->assertOk()->assertInertia(fn ($page) => $page->where('company.logoUrl', $url));
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/svg+xml')->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")->assertSee($svg, false);
        // SP-L1-07: the foreign owner now resolves into their own organization and is
        // rejected because the stored path belongs to $org, not a blanket membership miss.
        $this->actingAs($foreign)->withSession(['brand_id' => $foreignBrand->id])->get($url)->assertNotFound();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        foreach (['organizations/other/onboarding/logos/'.str_repeat('a', 32).'.svg', 'organizations/'.$org->id.'/onboarding/logos/../secret.svg'] as $invalid) {
            DB::table('onboarding_progress')->where('organization_id', $org->id)->update(['logo_path' => $invalid]);
            $this->get($url)->assertNotFound();
        }
    }
}
