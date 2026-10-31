<?php

namespace Tests\Feature\Seller\Settings\Limits;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

abstract class LimitsTestCase extends TenancyTestCase
{
    protected function cabinet(bool $configured = true): array
    {
        $result = parent::cabinet($configured);
        [, $organization, $first, $second] = $result;
        foreach ([$first, $second] as $brand) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $result;
    }
}
