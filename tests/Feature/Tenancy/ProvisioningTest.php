<?php

namespace Tests\Feature\Tenancy;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\Tenancy\CabinetFixtureSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProvisioningTest extends TenancyTestCase
{
    public function test_seed_refuses_a_second_installation_organization(): void
    {
        app(CabinetFixtureSeeder::class)->run();
        config(['features.E0-02.defaults.organization_id' => (string) Str::ulid()]);
        $this->expectException(\LogicException::class);
        app(CabinetFixtureSeeder::class)->run();
    }

    public function test_explicit_fixture_seed_creates_one_cabinet_multiple_brands_and_is_idempotent(): void
    {
        $seeder = app(CabinetFixtureSeeder::class);
        $seeder->run();
        $ids = Brand::withoutGlobalScopes()->orderBy('id')->pluck('id')->all();
        $seeder->run();
        $this->assertSame(1, Organization::query()->count());
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Membership::withoutGlobalScopes()->count());
        $this->assertCount(3, $ids);
        $modules = DB::table('brand_modules')->whereIn('brand_id', $ids)->get();
        $this->assertCount(6, $modules);
        foreach ($ids as $id) {
            $this->assertSame(['seller', 'vision'], $modules->where('brand_id', $id)->sortBy('module')->pluck('module')->values()->all());
            $this->assertTrue($modules->where('brand_id', $id)->every(fn ($module) => (bool) $module->enabled));
        }
        DB::table('brand_modules')->where('brand_id', $ids[0])->where('module', 'seller')->update(['enabled' => false]);
        $seeder->run();
        $this->assertFalse((bool) DB::table('brand_modules')->where('brand_id', $ids[0])->where('module', 'seller')->value('enabled'));
        $this->assertSame($ids, Brand::withoutGlobalScopes()->orderBy('id')->pluck('id')->all());
        $this->assertNull(Organization::query()->first()->owner_user_id);
        $this->assertNull(app(TenantContext::class)->organizationId);
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame(config('runtime.test_database'), DB::connection()->getDatabaseName());
    }
}
