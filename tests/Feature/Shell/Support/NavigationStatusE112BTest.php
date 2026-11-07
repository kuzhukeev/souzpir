<?php

namespace Tests\Feature\Shell\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

final class NavigationStatusE112BTest extends SupportTestCase
{
    public function test_every_seller_menu_item_has_a_get_route(): void
    {
        $navigation = json_decode(file_get_contents(resource_path('js/components/navigation/navigation.json')), true, flags: JSON_THROW_ON_ERROR);
        $seller = collect($navigation['modules'])->firstWhere('id', 'seller');
        self::assertNotEmpty($seller['children']);
        foreach ($seller['children'] as $item) {
            $route = Route::getRoutes()->match(Request::create($item['path'], 'GET'));
            self::assertNotNull($route, $item['path']);
        }
        self::assertNotContains('/seller/scenario/fields', array_column($seller['children'], 'path'));
    }

    public function test_status_requires_authentication_and_is_available_to_viewer_in_its_brand(): void
    {
        $this->get('/help/status')->assertRedirect('/login');
        [$owner, $organization, $brand] = $this->cabinet();
        [$viewer] = $this->member($organization, 'viewer', [$brand->id]);
        $this->signIn($viewer, $brand);
        $this->get('/help/status')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Shell/Support/Status')
            ->where('status.components.0.source', 'app_response')
            ->where('status.components.1.source', 'database_probe')
            ->where('status.components.2.source', 'unavailable')
            ->where('status.incidents.available', false));
    }
}
