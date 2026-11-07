<?php

namespace Tests\Feature\Seller\Conversations;

use App\Models\Membership;
use App\Seller\Conversations\AssignmentPolicy;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\SellerTask;
use App\Shell\Permissions\PermissionEvaluator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DatabaseAssignmentGuardTest extends TenancyTestCase
{
    public function test_direct_queries_cannot_assign_an_ineligible_member_or_move_tenant(): void
    {
        [, $org, $brand, $other] = $this->cabinet();
        [$editor] = $this->member($org, 'member', [$brand->id]);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        [$otherBrand] = $this->member($org, 'member', [$other->id]);
        [$disabled, $membership] = $this->member($org, 'member', [$brand->id]);
        DB::table('memberships')->where('id', $membership->id)->update(['status' => 'disabled']);
        [$conversation, $task] = $this->within($org, $brand, function () {
            $conversation = Conversation::create(['stage' => 'closed']);

            return [$conversation, SellerTask::create(['conversation_id' => $conversation->id, 'type' => 'followup'])];
        });
        foreach ([$conversation, $task] as $record) {
            foreach ([$viewer, $otherBrand, $disabled] as $invalid) {
                $this->reject(fn () => DB::table($record->getTable())->where('id', $record->id)->update(['assigned_user_id' => $invalid->id]));
                $this->reject(fn () => $record::withoutGlobalScopes()->toBase()->where('id', $record->id)->update(['assigned_user_id' => $invalid->id]));
            }
            $this->reject(fn () => DB::table($record->getTable())->where('id', $record->id)->update(['brand_id' => $other->id]));
            self::assertNull(DB::table($record->getTable())->where('id', $record->id)->value('assigned_user_id'));
            self::assertSame(1, DB::table($record->getTable())->where('id', $record->id)->update(['assigned_user_id' => $editor->id]));
        }
        $this->reject(fn () => DB::table('conversations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'assigned_user_id' => $viewer->id]));
        $this->reject(fn () => DB::table('seller_tasks')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $conversation->id, 'type' => 'followup', 'assigned_user_id' => $viewer->id]));
    }

    public function test_membership_mutations_cannot_invalidate_any_brand_or_organization(): void
    {
        [, $org, $brand, $other] = $this->cabinet();
        [$user, $membership] = $this->member($org, 'member', [$brand->id, $other->id]);
        $this->within($org, $brand, function () use ($user): void {
            $conversation = Conversation::create(['assigned_user_id' => $user->id]);
            SellerTask::create(['conversation_id' => $conversation->id, 'type' => 'followup', 'assigned_user_id' => $user->id]);
        });
        foreach ([['role' => 'viewer'], ['status' => 'disabled'], ['brand_ids' => json_encode([$other->id])]] as $change) {
            $this->reject(fn () => DB::table('memberships')->where('id', $membership->id)->update($change));
        }
        $this->reject(fn () => DB::table('memberships')->where('id', $membership->id)->delete());
        self::assertSame('member', $membership->fresh()->role);
        self::assertSame('active', $membership->fresh()->status);
        self::assertEqualsCanonicalizing([$brand->id, $other->id], $membership->fresh()->brand_ids);
        self::assertSame(1, DB::table('memberships')->where('id', $membership->id)->update(['brand_ids' => json_encode([$brand->id])]));
    }

    public function test_policy_config_divergence_fails_closed(): void
    {
        AssignmentPolicy::assertConfiguration();
        $user = $this->user();
        $abilities = config('permissions.abilities');
        $abilities['seller.dialogs.write'] = ['owner', 'admin', 'viewer'];
        config(['permissions.abilities' => $abilities]);
        $this->expectException(LogicException::class);
        app(PermissionEvaluator::class)->allows($user, 'org.read');
    }

    public function test_eloquent_assignment_fails_closed_when_permission_config_diverges(): void
    {
        [, $org, $brand] = $this->cabinet();
        [$user] = $this->member($org, 'member', [$brand->id]);
        $conversation = $this->within($org, $brand, fn () => Conversation::create([]));
        $abilities = config('permissions.abilities');
        $abilities['seller.dialogs.write'] = ['owner', 'admin'];
        config(['permissions.abilities' => $abilities]);
        $this->expectException(LogicException::class);
        $this->within($org, $brand, function () use ($user, $conversation): void {
            $conversation->assigned_user_id = $user->id;
            $conversation->save();
        });
    }

    public function test_membership_and_assignment_checks_are_isolated_by_organization(): void
    {
        [, $firstOrg, $firstBrand] = $this->cabinet();
        [, $secondOrg, $secondBrand] = $this->cabinet();
        [$user, $firstMembership] = $this->member($firstOrg, 'member', [$firstBrand->id]);
        $secondMembership = $this->within($secondOrg, null, fn () => Membership::create(['user_id' => $user->id, 'role' => 'member', 'brand_ids' => [$secondBrand->id]]));
        $first = $this->within($firstOrg, $firstBrand, fn () => Conversation::create(['assigned_user_id' => $user->id]));
        $second = $this->within($secondOrg, $secondBrand, fn () => Conversation::create(['assigned_user_id' => $user->id]));
        $this->reject(fn () => DB::table('conversations')->where('id', $first->id)->update(['organization_id' => $secondOrg->id, 'brand_id' => $secondBrand->id]));
        $this->reject(fn () => DB::table('memberships')->where('id', $firstMembership->id)->update(['organization_id' => $secondOrg->id]));
        $this->reject(fn () => DB::table('memberships')->where('id', $firstMembership->id)->update(['role' => 'viewer']));
        DB::table('conversations')->where('id', $first->id)->update(['assigned_user_id' => null]);
        self::assertSame(1, DB::table('memberships')->where('id', $firstMembership->id)->update(['role' => 'viewer']));
        self::assertSame('member', $secondMembership->fresh()->role);
        self::assertSame($user->id, DB::table('conversations')->where('id', $second->id)->value('assigned_user_id'));
    }

    public function test_failed_multirow_change_rolls_back_every_row(): void
    {
        [, $org, $brand] = $this->cabinet();
        [$user] = $this->member($org, 'member', [$brand->id]);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        [$one, $two] = $this->within($org, $brand, fn () => [Conversation::create([]), Conversation::create([])]);
        $this->reject(fn () => DB::statement('UPDATE conversations SET assigned_user_id = CASE WHEN id = ? THEN ? ELSE ? END WHERE id IN (?, ?)', [$one->id, $user->id, $viewer->id, $one->id, $two->id]));
        self::assertSame(0, DB::table('conversations')->whereIn('id', [$one->id, $two->id])->whereNotNull('assigned_user_id')->count());
    }

    private function reject(\Closure $operation): void
    {
        try {
            DB::transaction($operation);
            self::fail('Invalid assignment operation was accepted.');
        } catch (QueryException $error) {
            self::assertSame('23514', $error->errorInfo[0] ?? null);
        }
    }
}
