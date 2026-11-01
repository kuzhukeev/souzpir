<?php

namespace Tests\Feature\Shell\InvitationIssuance;

use App\Shell\Team\Models\Invitation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Tenancy\TenancyTestCase;
use Tests\Support\IsolatedAuthDatabase;

final class InvitationIssuanceSchemaTest extends TenancyTestCase
{
    public function test_migration_preserves_existing_invitations_and_rejects_foreign_organization_links(): void
    {
        IsolatedAuthDatabase::assert();
        self::assertSame('souzpir_test_sp_e1_06b', DB::connection()->getDatabaseName());
        [$owner, $org, $brand] = $this->cabinet();
        [$foreignOwner, $foreignOrg] = $this->cabinet(false);
        $invitation = $this->within($org, null, fn () => Invitation::create([
            'email' => 'existing@example.test', 'role' => 'viewer', 'brand_ids' => [$brand->id],
            'token_hash' => hash('sha256', 'synthetic-existing-token'), 'invited_by' => $owner->id, 'expires_at' => now()->addDay(),
        ]));
        $migration = require base_path('database/migrations/E1-06B/2026_11_01_130600_create_invitation_issuance_attempts.php');
        $migration->down();
        self::assertFalse(Schema::hasTable('invitation_issuance_attempts'));
        self::assertTrue(DB::table('invitations')->where('id', $invitation->id)->where('organization_id', $org->id)->exists());
        $migration->up();
        self::assertTrue(Schema::hasTable('invitation_issuance_attempts'));
        self::assertTrue(DB::table('invitations')->where('id', $invitation->id)->where('organization_id', $org->id)->exists());
        $receipt = ['organization_id' => $org->id, 'actor_user_id' => $owner->id, 'attempt_hash' => str_repeat('a', 64),
            'invitation_id' => $invitation->id, 'created_at' => now()];
        foreach ([
            [...$receipt, 'organization_id' => $foreignOrg->id, 'actor_user_id' => $foreignOwner->id, 'attempt_hash' => str_repeat('b', 64)],
            [...$receipt, 'actor_user_id' => $foreignOwner->id, 'attempt_hash' => str_repeat('c', 64)],
        ] as $invalid) {
            try {
                DB::transaction(fn () => DB::table('invitation_issuance_attempts')->insert($invalid));
                self::fail('A cross-organization receipt must fail its foreign key.');
            } catch (QueryException $error) {
                self::assertSame('23503', $error->getCode());
            }
        }
        DB::table('invitation_issuance_attempts')->insert($receipt);
        self::assertSame(1, DB::table('invitation_issuance_attempts')->count());
    }
}
