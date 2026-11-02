<?php

namespace Tests\Feature\Seller\Settings\Greetings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;

final class GreetingsTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_greetings_save_empty_defaults_and_brand_isolation(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/settings/greetings', ['greetings' => ['telegram' => 'Здравствуйте!', 'widget' => '']])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/settings/greetings')->assertOk()->assertInertia(fn (Assert $page) => $page->where('greetings.telegram', 'Здравствуйте!')->where('greetings.widget', ''));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/settings/greetings')->assertOk()->assertInertia(fn (Assert $page) => $page->where('greetings', []));
        $this->post('/seller/settings/greetings', ['greetings' => []])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/brand/switch', ['brand_id' => $brand->id])->assertRedirect();
        $this->get('/seller/settings/greetings')->assertOk()->assertInertia(fn (Assert $page) => $page->where('greetings.telegram', 'Здравствуйте!'));
    }

    public function test_access_and_validation(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/settings/greetings', ['greetings' => ['unknown' => 'x']])->assertSessionHasErrors('greetings');
        $this->post('/seller/settings/greetings', ['greetings' => ['telegram' => str_repeat('x', 1001)]])->assertSessionHasErrors('greetings.telegram');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/settings/greetings')->assertOk();
        $this->post('/seller/settings/greetings', ['greetings' => ['telegram' => 'x']])->assertForbidden();
    }
}
