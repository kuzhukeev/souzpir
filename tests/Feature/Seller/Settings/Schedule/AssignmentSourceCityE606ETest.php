<?php

namespace Tests\Feature\Seller\Settings\Schedule;

use App\Seller\Runtime\BrandRuntimeSchedule;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class AssignmentSourceCityE606ETest extends TenancyTestCase
{
    private function channel(string $org, string $brand, string $type, ?string $name = null): string
    {
        $id = (string) Str::ulid();
        DB::table('channel_accounts')->insert(['id' => $id, 'organization_id' => $org, 'brand_id' => $brand, 'type' => $type, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function conversation(string $org, string $brand, ?string $channel = null, ?string $city = null): string
    {
        $contact = null;
        if ($city !== null) {
            $contact = (string) Str::ulid();
            DB::table('contacts')->insert(['id' => $contact, 'organization_id' => $org, 'brand_id' => $brand, 'name' => 'Клиент', 'city' => $city, 'created_at' => now(), 'updated_at' => now()]);
        }
        $id = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $id, 'organization_id' => $org, 'brand_id' => $brand, 'mode' => 'manager', 'channel_account_id' => $channel, 'contact_id' => $contact,
            'state' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_source_rule_beats_channel_rule_and_default(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        [$manager] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        $siteA = $this->channel($org->id, $brand->id, 'widget', 'Сайт А');
        $siteB = $this->channel($org->id, $brand->id, 'widget', 'Сайт Б');
        $members = [['id' => $owner->id, 'enabled' => true, 'max_active' => null], ['id' => $manager->id, 'enabled' => true, 'max_active' => null]];
        $settings = ['assignment' => ['method' => 'fixed', 'fixed' => $owner->id, 'fixed_channels' => ['widget' => $owner->id], 'fixed_sources' => [$siteB => $manager->id], 'members' => $members]];
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $a = $this->conversation($org->id, $brand->id, $siteA);
        $b = $this->conversation($org->id, $brand->id, $siteB);
        app(BrandRuntimeSchedule::class)->assignWaiting($tenant, $a, $settings);
        app(BrandRuntimeSchedule::class)->assignWaiting($tenant, $b, $settings);
        $this->assertDatabaseHas('conversations', ['id' => $a, 'assigned_user_id' => $owner->id]);
        $this->assertDatabaseHas('conversations', ['id' => $b, 'assigned_user_id' => $manager->id]);
    }

    public function test_city_rules_match_case_insensitive_and_fall_back_to_default_or_nobody(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        [$manager] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        $members = [['id' => $owner->id, 'enabled' => true, 'max_active' => null], ['id' => $manager->id, 'enabled' => true, 'max_active' => null]];
        $rules = [['city' => 'Екатеринбург', 'user_id' => $manager->id]];
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $service = app(BrandRuntimeSchedule::class);
        $match = $this->conversation($org->id, $brand->id, null, '  екатеринбург ');
        $other = $this->conversation($org->id, $brand->id, null, 'Пермь');
        $unknown = $this->conversation($org->id, $brand->id);
        $service->assignWaiting($tenant, $match, ['assignment' => ['method' => 'city', 'city_rules' => $rules, 'members' => $members]]);
        $service->assignWaiting($tenant, $other, ['assignment' => ['method' => 'city', 'city_rules' => $rules, 'members' => $members]]);
        $this->assertDatabaseHas('conversations', ['id' => $match, 'assigned_user_id' => $manager->id]);
        $this->assertDatabaseHas('conversations', ['id' => $other, 'assigned_user_id' => null]);
        $service->assignWaiting($tenant, $other, ['assignment' => ['method' => 'city', 'fixed' => $owner->id, 'city_rules' => $rules, 'members' => $members]]);
        $service->assignWaiting($tenant, $unknown, ['assignment' => ['method' => 'city', 'fixed' => $owner->id, 'city_rules' => $rules, 'members' => $members]]);
        $this->assertDatabaseHas('conversations', ['id' => $other, 'assigned_user_id' => $owner->id]);
        $this->assertDatabaseHas('conversations', ['id' => $unknown, 'assigned_user_id' => $owner->id]);
        // A disabled member never gets a dialogue by city.
        $disabled = $this->conversation($org->id, $brand->id, null, 'Екатеринбург');
        $members[1]['enabled'] = false;
        $service->assignWaiting($tenant, $disabled, ['assignment' => ['method' => 'city', 'city_rules' => $rules, 'members' => $members]]);
        $this->assertDatabaseHas('conversations', ['id' => $disabled, 'assigned_user_id' => null]);
    }

    public function test_settings_store_sources_and_cities_with_brand_isolation_and_validation(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $site = $this->channel($org->id, $brand->id, 'widget', 'Основной сайт');
        $foreign = $this->channel($org->id, $other->id, 'telegram', 'Чужой бот');
        DB::table('contacts')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'city' => 'Казань', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('contacts')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $other->id, 'city' => 'Омск', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $base = ['notify_target' => 'owner', 'members' => []];
        $this->post('/seller/settings/assignment', [...$base, 'method' => 'fixed', 'fixed' => $owner->id, 'fixed_sources' => [$site => $owner->id]])->assertSessionHasNoErrors();
        $this->post('/seller/settings/assignment', [...$base, 'method' => 'city', 'fixed' => null, 'city_rules' => [['city' => ' Казань ', 'user_id' => $owner->id]]])->assertSessionHasNoErrors();
        $this->get('/seller/settings?tab=assignment')->assertOk()->assertInertia(fn ($page) => $page
            ->where('settings.assignment.method', 'city')->where('settings.assignment.city_rules.0.city', 'Казань')
            ->where('sources', [['id' => $site, 'label' => 'Сайт · Основной сайт']])->where('cities', ['Казань']));
        $this->post('/seller/settings/assignment', [...$base, 'method' => 'fixed', 'fixed' => $owner->id, 'fixed_sources' => [$foreign => $owner->id]])->assertSessionHasErrors('fixed_sources');
        $this->post('/seller/settings/assignment', [...$base, 'method' => 'fixed', 'fixed' => $owner->id, 'fixed_sources' => [$site => (string) Str::ulid()]])->assertSessionHasErrors('members');
        $this->post('/seller/settings/assignment', [...$base, 'method' => 'city', 'city_rules' => []])->assertSessionHasErrors('city_rules');
        $this->post('/seller/settings/assignment', [...$base, 'method' => 'city', 'city_rules' => [['city' => 'Казань', 'user_id' => $owner->id], ['city' => 'казань', 'user_id' => $owner->id]]])->assertSessionHasErrors('city_rules');
        $this->post('/seller/settings/assignment', [...$base, 'method' => 'city', 'city_rules' => [['city' => '', 'user_id' => $owner->id]]])->assertSessionHasErrors('city_rules.0.city');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/settings/assignment', [...$base, 'method' => 'city', 'city_rules' => [['city' => 'Казань', 'user_id' => $owner->id]]])->assertForbidden();
    }
}
