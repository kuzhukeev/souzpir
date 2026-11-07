<?php

namespace Tests\Feature\Shell\Team;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\SellerTask;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

final class TeamAssignmentsTest extends TeamTestCase
{
    public function test_assignments_in_any_organization_brand_block_disabling_without_changes(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->signIn($owner, $brand);
        foreach ([[true, false], [false, true]] as [$conversation, $task]) {
            [$target, $member] = $this->member($org, 'member', [$brand->id, $other->id]);
            $target->forceFill(['remember_token' => Str::random(60)])->save();
            $target->refresh();
            $version = $target->auth_version;
            $remember = $target->getRememberToken();
            $rows = $this->assigned($other, $target, $conversation, $task);
            $response = $this->postJson('/team/'.$member->id.'/disable');
            self::assertSame(422, $response->status());
            $response->assertJsonValidationErrors('member');
            self::assertSame(__('Shell/Team/messages.assignments_disable'), $response->json('message'));
            self::assertSame('active', $member->fresh()->status);
            self::assertSame($version, $target->fresh()->auth_version);
            self::assertTrue(hash_equals($remember, $target->fresh()->getRememberToken()));
            self::assertSame($conversation ? $target->id : null, DB::table('conversations')->where('id', $rows[0]->id)->value('assigned_user_id'));
            if ($task) {
                self::assertSame($target->id, DB::table('seller_tasks')->where('id', $rows[1]->id)->value('assigned_user_id'));
            }
        }
        self::assertSame(0, DB::table('audit_logs')->where('action', 'team.member.disabled')->count());
    }

    public function test_removing_assignee_brand_access_is_blocked_for_conversations_and_tasks(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$target, $member] = $this->member($org, 'admin', []);
        $target->refresh();
        $version = $target->auth_version;
        $conversation = $this->assigned($other, $target, true, false)[0];
        $task = $this->assigned($brand, $target, false, true)[1];
        $this->signIn($owner, $brand);
        foreach ([['viewer', [$brand->id]], ['editor', [$other->id]]] as [$role, $ids]) {
            $response = $this->patchJson('/team/'.$member->id, ['role' => $role, 'brand_ids' => $ids]);
            self::assertSame(422, $response->status());
            $response->assertJsonValidationErrors('brand_ids');
            self::assertSame(__('Shell/Team/messages.assignments_grants'), $response->json('message'));
            self::assertSame('admin', $member->fresh()->role);
            self::assertSame([], $member->fresh()->brand_ids);
            self::assertSame('active', $member->fresh()->status);
            self::assertSame($version, $target->fresh()->auth_version);
        }
        self::assertSame(0, DB::table('audit_logs')->where('action', 'team.member.updated')->count());
        self::assertSame(200, $this->patchJson('/team/'.$member->id, ['role' => 'manager', 'brand_ids' => [$brand->id, $other->id]])->status());
        foreach ([[$other, $conversation], [$brand, $task]] as [$scope, $row]) {
            app(TenantContextRunner::class)->run(new TenantContext($org->id, $scope->id, source: 'job'), function () use ($row): void {
                $row->refresh();
                self::assertTrue($row->save()); // Existing seller reference validation still accepts the assignee.
            });
        }
        self::assertSame(1, DB::table('audit_logs')->where('action', 'team.member.updated')->count());
    }

    public function test_losing_dialogue_write_permission_is_rejected_while_assigned_and_allowed_without_assignments(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        self::assertNotContains('viewer', config('permissions.abilities')['seller.dialogs.write']);
        $this->signIn($owner, $brand);
        foreach ([[true, false], [false, true]] as [$conversationAssigned, $taskExists]) {
            [$user, $member] = $this->member($org, 'member', [$brand->id], true);
            $user->refresh();
            $version = $user->auth_version;
            [$conversation, $task] = $this->assigned($brand, $user, $conversationAssigned, $taskExists);
            $response = $this->patchJson('/team/'.$member->id, ['role' => 'viewer', 'brand_ids' => [$brand->id]]);
            self::assertSame(422, $response->status());
            $response->assertJsonValidationErrors('role');
            self::assertSame(__('Shell/Team/messages.assignments_permission'), $response->json('message'));
            self::assertSame('member', $member->fresh()->role);
            self::assertTrue($member->fresh()->settings['sales_manager']);
            self::assertSame([$brand->id], $member->fresh()->brand_ids);
            self::assertSame($version, $user->fresh()->auth_version);
            if ($conversationAssigned) {
                self::assertSame($user->id, $conversation->fresh()->assigned_user_id);
            }
            if ($taskExists) {
                self::assertSame($user->id, $task->fresh()->assigned_user_id);
            }
        }
        self::assertSame(0, DB::table('audit_logs')->where('action', 'team.member.updated')->count());
        [, $unassigned] = $this->member($org, 'member', [$brand->id]);
        self::assertSame(200, $this->patchJson('/team/'.$unassigned->id, ['role' => 'viewer', 'brand_ids' => [$brand->id]])->status());
        self::assertSame('viewer', $unassigned->fresh()->role);
        self::assertSame(1, DB::table('audit_logs')->where('action', 'team.member.updated')->count());
    }

    public function test_assignment_committing_first_serializes_with_role_downgrade(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        [$user, $member] = $this->member($org, 'member', [$brand->id]);
        [$conversation] = $this->assigned($brand, null, false, false);
        $this->signIn($owner, $brand);
        $command = [PHP_BINARY, '-d', 'sys_temp_dir=.local/tmp', base_path('tests/Feature/Shell/Team/Fixtures/hold-assignment.php'), $org->id, $brand->id, $user->id, $conversation->id];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), null, ['bypass_shell' => true]);
        self::assertIsResource($process);
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 15);
        try {
            self::assertSame("locked\n", fgets($pipes[1]), 'Child did not acquire the organization lock.');
            $started = microtime(true);
            $response = $this->patchJson('/team/'.$member->id, ['role' => 'viewer', 'brand_ids' => [$brand->id]]);
            self::assertGreaterThan(0.2, microtime(true) - $started);
            self::assertSame(422, $response->status());
            $response->assertJsonValidationErrors('role');
            self::assertSame('member', $member->fresh()->role);
            self::assertSame(1, DB::table('seller_tasks')->where('assigned_user_id', $user->id)->count());
            self::assertSame(0, DB::table('audit_logs')->where('action', 'team.member.updated')->count());
            self::assertStringContainsString('committed', stream_get_contents($pipes[1]));
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }

    public function test_assignment_counts_are_real_assignee_and_selected_brand_scoped_and_permission_gated(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$target, $member] = $this->member($org, 'member', [$brand->id, $other->id]);
        $this->assigned($brand, $target, true, true);
        $this->assigned($brand, $target, true, false);
        $this->assigned($other, $target, true, true);
        $this->assigned($other, $target, false, true);
        $this->assigned($brand, null, false, true);
        $this->assigned($brand, $owner, true, true);
        $this->signIn($owner, $brand);
        $this->get('/team/'.$member->id)->assertInertia(fn (Assert $page) => $page
            ->where('team.assignments', ['available' => true, 'brand_id' => $brand->id, 'conversations' => 2, 'tasks' => 1, 'can_transfer' => false]));
        $this->signIn($owner, $other);
        $this->get('/team/'.$member->id)->assertInertia(fn (Assert $page) => $page
            ->where('team.assignments.conversations', 1)->where('team.assignments.tasks', 2)->where('team.assignments.brand_id', $other->id));
        config(['permissions.abilities' => [...config('permissions.abilities'), 'seller.dialogs.read' => []]]);
        $this->get('/team/'.$member->id)->assertInertia(fn (Assert $page) => $page
            ->where('team.assignments.available', false)->where('team.assignments.conversations', null)->where('team.assignments.tasks', null));
    }

    public function test_foreign_organization_assignments_neither_leak_counts_nor_block_local_changes(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$target, $member] = $this->member($org, 'member', [$brand->id, $other->id]);
        $foreign = Organization::create(['name' => 'Foreign assignments']);
        $foreignBrand = app(TenantContextRunner::class)->run(new TenantContext($foreign->id, source: 'job'), function () use ($target): Brand {
            $scope = Brand::create(['name' => 'Foreign brand', 'theme' => 'neutral']);
            Membership::create(['user_id' => $target->id, 'role' => 'member', 'brand_ids' => [$scope->id]]);

            return $scope;
        });
        [$conversation, $task] = $this->assigned($foreignBrand, $target, true, true);
        $this->signIn($owner, $brand);
        $this->get('/team/'.$member->id)->assertInertia(fn (Assert $page) => $page
            ->where('team.assignments.conversations', 0)->where('team.assignments.tasks', 0));
        self::assertSame(200, $this->patchJson('/team/'.$member->id, ['role' => 'viewer', 'brand_ids' => [$brand->id]])->status());
        self::assertSame(200, $this->postJson('/team/'.$member->id.'/disable')->status());
        self::assertSame('disabled', $member->fresh()->status);
        self::assertSame($target->id, DB::table('conversations')->where('id', $conversation->id)->value('assigned_user_id'));
        self::assertSame($target->id, DB::table('seller_tasks')->where('id', $task->id)->value('assigned_user_id'));
        self::assertSame(2, DB::table('audit_logs')->whereIn('action', ['team.member.updated', 'team.member.disabled'])->count());
    }

    private function assigned(Brand $brand, ?User $user, bool $conversationAssigned, bool $taskExists): array
    {
        return app(TenantContextRunner::class)->run(new TenantContext($brand->organization_id, $brand->id, source: 'job'), function () use ($user, $conversationAssigned, $taskExists): array {
            $conversation = Conversation::create(['assigned_user_id' => $conversationAssigned ? $user?->id : null, 'stage' => 'closed']);
            $task = $taskExists ? SellerTask::create(['conversation_id' => $conversation->id, 'type' => 'followup', 'assigned_user_id' => $user?->id, 'status' => 'done']) : null;

            return [$conversation, $task];
        });
    }
}
