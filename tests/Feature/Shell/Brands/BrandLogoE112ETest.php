<?php

namespace Tests\Feature\Shell\Brands;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Tenancy\TenancyTestCase;

final class BrandLogoE112ETest extends TenancyTestCase
{
    public function test_png_svg_validation_and_brand_access(): void
    {
        Storage::fake('local');
        [$owner, $org, $brand, $other] = $this->cabinet();
        $base = ['_method' => 'PATCH', 'theme' => 'neutral', 'promo_card' => true];
        $url = '/brands/'.$brand->id.'/settings/theme';
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4"/></svg>';
        $this->post($url, [...$base, 'logo' => UploadedFile::fake()->createWithContent('logo.svg', $svg)])->assertSessionHasNoErrors();
        $settings = json_decode(DB::table('brands')->where('id', $brand->id)->value('settings'), true);
        self::assertStringEndsWith('.svg', $settings['logo_path']);
        Storage::disk('local')->assertExists($settings['logo_path']);
        $this->get('/brands/'.$brand->id.'/settings/logo')->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->post($url, [...$base, 'logo' => UploadedFile::fake()->image('logo.jpg')])->assertSessionHasErrors('logo');
        $this->post($url, [...$base, 'logo' => UploadedFile::fake()->create('large.png', 1025, 'image/png')])->assertSessionHasErrors('logo');
        $this->post($url, [...$base, 'logo' => UploadedFile::fake()->image('logo.png')])->assertSessionHasNoErrors();
        self::assertNull(json_decode(DB::table('brands')->where('id', $other->id)->value('settings'), true)['logo_path'] ?? null);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post($url, [...$base, 'logo' => UploadedFile::fake()->image('logo.png')])->assertForbidden();
    }
}
