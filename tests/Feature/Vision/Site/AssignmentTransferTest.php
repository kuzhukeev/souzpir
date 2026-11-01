<?php

namespace Tests\Feature\Vision\Site;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class AssignmentTransferTest extends TenancyTestCase
{
    public function test_transfer_checks_access_brand_and_preserves_history(): void
    {
        [$owner, $org, $brand, $other, $original] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id,
            'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        [, $replacement] = $this->member($org, 'member', [$brand->id]);
        [, $otherBrandMember] = $this->member($org, 'member', [$other->id]);
        [, , , , $foreign] = $this->cabinet(false);
        $id = (string) Str::ulid();
        DB::table('actions')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'title' => 'Действие', 'responsible' => $original->id, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $url = '/vision/actions/'.$id;
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->patch($url, ['responsible' => $replacement->id])->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        foreach ([$otherBrandMember, $foreign] as $invalid) {
            $this->patch($url, ['responsible' => $invalid->id])->assertSessionHasErrors('responsible');
        }
        $this->patch($url, ['responsible' => 'invalid'])->assertSessionHasErrors('responsible');
        $this->patch($url, ['responsible' => $replacement->id])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('actions', ['id' => $id, 'responsible' => $replacement->id]);
        $audit = DB::table('audit_logs')->where('subject_id', $id)->where('action', 'vision.action.updated')->latest('created_at')->first();
        self::assertSame($original->id, json_decode($audit->before, true)['responsible']);
        self::assertSame($replacement->id, json_decode($audit->after, true)['responsible']);
        DB::table('actions')->where('id', $id)->update(['status' => 'awaiting_measurement', 'completed_at' => '2026-10-31', 'comment' => 'Готово']);
        $this->patch($url, ['responsible' => $original->id])->assertSessionHasErrors('responsible');
        $this->assertDatabaseHas('actions', ['id' => $id, 'responsible' => $replacement->id, 'status' => 'awaiting_measurement']);
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->patch($url, ['responsible' => $original->id])->assertNotFound();
    }
}
