<?php

namespace Tests\Feature\Vision\Site;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ResponsibleTenantTest extends TenancyTestCase
{
    public function test_action_responsible_must_belong_to_the_same_organization(): void
    {
        [, $organization, $brand, , $membership] = $this->cabinet();
        [, , , , $foreignMembership] = $this->cabinet(false);
        $actionId = (string) Str::ulid();
        $attributes = [
            'id' => $actionId,
            'organization_id' => $organization->id,
            'brand_id' => $brand->id,
            'title' => 'Проверка ответственности',
            'responsible' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('actions')->insert($attributes);
        DB::table('actions')->where('id', $actionId)->update(['responsible' => $membership->id]);
        self::assertSame($membership->id, DB::table('actions')->where('id', $actionId)->value('responsible'));

        foreach (['insert', 'update'] as $operation) {
            try {
                // PostgreSQL must roll back the failing statement to a savepoint so the
                // outer test transaction remains usable for subsequent assertions.
                DB::transaction(function () use ($operation, $attributes, $actionId, $foreignMembership): void {
                    if ($operation === 'insert') {
                        DB::table('actions')->insert([...$attributes, 'id' => (string) Str::ulid(), 'responsible' => $foreignMembership->id]);
                    } else {
                        DB::table('actions')->where('id', $actionId)->update(['responsible' => $foreignMembership->id]);
                    }
                });
                self::fail('The database accepted a responsible from another organization.');
            } catch (QueryException $error) {
                self::assertSame('23503', $error->errorInfo[0] ?? null);
            }
        }

        self::assertSame(1, DB::table('actions')->where('organization_id', $organization->id)->count());
        self::assertSame($membership->id, DB::table('actions')->where('id', $actionId)->value('responsible'));
        DB::table('actions')->where('id', $actionId)->update(['responsible' => null]);
        self::assertNull(DB::table('actions')->where('id', $actionId)->value('responsible'));
    }
}
