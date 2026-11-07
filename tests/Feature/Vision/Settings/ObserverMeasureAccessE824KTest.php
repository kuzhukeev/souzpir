<?php

namespace Tests\Feature\Vision\Settings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ObserverMeasureAccessE824KTest extends TenancyTestCase
{
    public function test_viewer_keeps_measurement_routes_but_not_full_answers_access(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        [$editor] = $this->member($org, 'member', [$brand->id]);

        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/vision/runs')->assertOk();

        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $members = collect($this->get('/vision/settings', ['X-Inertia-Partial-Component' => 'Vision/Settings/Index', 'X-Inertia-Partial-Data' => 'members'])->assertOk()->inertiaProps('members'))->keyBy('id');
        self::assertFalse($members[$viewer->id]['canReadAnswers']);
        self::assertTrue($members[$editor->id]['canReadAnswers']);
    }
}
