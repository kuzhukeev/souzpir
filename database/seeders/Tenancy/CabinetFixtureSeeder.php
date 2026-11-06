<?php

namespace Database\Seeders\Tenancy;

use App\Models\Brand;
use App\Models\Organization;
use App\Tenancy\Cabinet;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class CabinetFixtureSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new LogicException('Fixture seeding is local/testing only.');
        }
        DB::transaction(function (): void {
            $id = app(Cabinet::class)->organizationId();
            // A cabinet-wide lock also rejects simultaneous provisioning with different IDs.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['souzpir:initial-cabinet']);
            $organization = Organization::query()->find($id);
            if ($organization) {
                // Never silently reconfigure an existing cabinet or reset grants.
                return;
            }
            if (Organization::query()->exists()) {
                throw new LogicException('A cabinet already exists with another installation ID.');
            }
            $organization = new Organization(['name' => 'Союз Пир · демонстрационные данные']);
            $organization->id = $id;
            $organization->save();
            // Initial provisioning only: no default user, password or membership.
            // Auth/onboarding later assigns the first owner in its transaction.
            app(TenantContextRunner::class)->run(new TenantContext($organization->id), function (): void {
                foreach (['confectionery' => 'Кондитерский бренд', 'pyro' => 'Пиротехнический бренд', 'neutral' => 'Нейтральный бренд'] as $theme => $name) {
                    $brand = Brand::create(['name' => $name.' · демо', 'theme' => $theme]);
                    foreach (['seller', 'vision'] as $module) {
                        DB::table('brand_modules')->insert([
                            'id' => (string) Str::ulid(), 'organization_id' => $brand->organization_id,
                            'brand_id' => $brand->id, 'module' => $module, 'enabled' => true,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
            });
        });
    }
}
