<?php

namespace Tests\Feature\Shell\Brands;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Tenancy\TenancyTestCase;

final class OnboardingSvgLogoE102GTest extends TenancyTestCase
{
    public function test_onboarding_svg_is_private_and_limited_to_authorized_brand(): void
    {
        Storage::fake('local');
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$member] = $this->member($org, 'member', [$other->id]);
        $path = 'organizations/'.$org->id.'/onboarding/logos/'.str_repeat('a', 32).'.svg';
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>';
        Storage::disk('local')->put($path, $svg);
        DB::table('brands')->where('id', $brand->id)->update(['settings' => json_encode(['logo_path' => $path], JSON_THROW_ON_ERROR)]);
        $url = '/brands/'.$brand->id.'/settings/logo';
        $this->get($url)->assertRedirect();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get($url)->assertOk()->assertHeader('Content-Type', 'image/svg+xml')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")->assertSee($svg, false);
        $this->actingAs($member)->withSession(['brand_id' => $other->id])->get($url)->assertNotFound();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        DB::table('brands')->where('id', $brand->id)->update(['settings' => json_encode(['logo_path' => 'organizations/other/onboarding/logos/'.str_repeat('a', 32).'.svg'], JSON_THROW_ON_ERROR)]);
        $this->get($url)->assertNotFound();
        DB::table('brands')->where('id', $brand->id)->update(['settings' => json_encode(['logo_path' => 'organizations/'.$org->id.'/onboarding/logos/not-valid.svg'], JSON_THROW_ON_ERROR)]);
        $this->get($url)->assertNotFound();
    }
}
