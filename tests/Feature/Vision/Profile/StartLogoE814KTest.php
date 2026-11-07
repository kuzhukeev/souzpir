<?php

namespace Tests\Feature\Vision\Profile;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class StartLogoE814KTest extends TenancyTestCase
{
    public function test_uploaded_brand_logo_is_private_and_isolated(): void
    {
        Storage::fake('local');
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $url = '/brands/'.$brand->id.'/settings/theme';
        $this->post($url, ['_method' => 'PATCH', 'theme' => 'neutral', 'promo_card' => true, 'logo' => UploadedFile::fake()->image('brand.png')])->assertSessionHasNoErrors();
        $this->get('/vision')->assertOk()->assertInertia(fn ($page) => $page->where('brandAppearance.logo_url', route('brands.settings.logo', $brand->id)));
        $this->get('/brands/'.$brand->id.'/settings/logo')->assertOk();
        $this->post($url, ['_method' => 'PATCH', 'theme' => 'neutral', 'promo_card' => true, 'logo' => UploadedFile::fake()->image('bad.jpg')])->assertSessionHasErrors('logo');
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/vision')->assertOk()->assertInertia(fn ($page) => $page->where('brandAppearance.logo_url', null));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post($url, ['_method' => 'PATCH', 'theme' => 'neutral', 'promo_card' => true, 'logo' => UploadedFile::fake()->image('brand.png')])->assertForbidden();
    }
}
