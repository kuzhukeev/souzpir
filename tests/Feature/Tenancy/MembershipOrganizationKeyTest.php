<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MembershipOrganizationKeyTest extends TenancyTestCase
{
    public function test_composite_reference_rejects_membership_from_another_organization(): void
    {
        [, $orgA, , , $memberA] = $this->cabinet();
        [, $orgB, , , $memberB] = $this->cabinet(false);

        Schema::create('membership_organization_probe', function (Blueprint $table): void {
            $table->ulid('organization_id');
            $table->ulid('responsible')->nullable();
            $table->foreign(['responsible', 'organization_id'])
                ->references(['id', 'organization_id'])->on('memberships')->restrictOnDelete();
        });

        DB::table('membership_organization_probe')->insert(['organization_id' => $orgA->id, 'responsible' => $memberA->id]);
        DB::table('membership_organization_probe')->insert(['organization_id' => $orgA->id, 'responsible' => null]);
        $this->assertSame(2, DB::table('membership_organization_probe')->count());

        try {
            DB::transaction(fn () => DB::table('membership_organization_probe')->insert([
                'organization_id' => $orgA->id, 'responsible' => $memberB->id,
            ]));
            $this->fail('A membership from a different organization was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame('23503', $exception->errorInfo[0]);
        }
        $this->assertNotSame($orgA->id, $orgB->id);
        $this->assertSame(2, DB::table('membership_organization_probe')->count());
    }
}
