<?php

namespace Tests\Feature\Shell\Auth;

use App\Models\Organization;
use App\Models\User;
use App\Shell\Auth\InvitationExchangeGrants;
use App\Shell\Team\Models\Invitation;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class InvitationExchangeConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_late_request_from_old_document_cannot_replace_new_document_grant(): void
    {
        $org = Organization::create(['name' => 'Epoch race fixture']);
        config(['features.E0-02.defaults.organization_id' => $org->id]);
        $owner = User::create(['name' => 'Owner', 'email' => 'epoch-owner@example.test', 'password' => 'only-test-password']);
        $tokens = [str_repeat('X', 64), str_repeat('Y', 64)];
        $invites = [];
        foreach ($tokens as $index => $token) {
            $invites[] = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $owner->id), fn () => Invitation::create([
                'email' => 'epoch-member'.$index.'@example.test', 'role' => 'member', 'brand_ids' => [],
                'token_hash' => hash('sha256', $token), 'invited_by' => $owner->id, 'expires_at' => now()->addDay(),
            ]));
        }
        $session = bin2hex(random_bytes(20));
        $request = Request::create('/invitations/open');
        $request->setLaravelSession(new Store('test', new ArraySessionHandler(120), $session));
        $old = app(InvitationExchangeGrants::class)->beginDocument($request);
        $new = app(InvitationExchangeGrants::class)->beginDocument($request);
        $key = hash_hmac('sha256', 'invitation-session:'.$session, (string) config('app.key'));
        $workers = [];
        DB::beginTransaction();
        try {
            DB::table('invitation_exchange_sessions')->where('session_hash', $key)->lockForUpdate()->first();
            foreach ([$old, $new] as $index => $epoch) {
                $input = ['org' => $org->id, 'session' => $session, 'epoch' => $epoch, 'hash' => hash('sha256', $tokens[$index])];
                $worker = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/invitation-exchange-worker.php'], base_path(), ['APP_ENV' => 'testing'], json_encode($input, JSON_THROW_ON_ERROR), 30);
                $worker->start();
                $workers[] = $worker;
            }
            $pids = [];
            $deadline = microtime(true) + 20;
            do {
                $pids = [];
                foreach ($workers as $worker) {
                    if (preg_match('/ready:(\d+)/', $worker->getOutput(), $match)) {
                        $pids[] = (int) $match[1];
                    }
                }
                if (count($pids) < 2) {
                    usleep(10000);
                }
            } while (count($pids) < 2 && microtime(true) < $deadline);
            self::assertCount(2, $pids);
            do {
                $blocked = DB::table('pg_locks')->whereIn('pid', $pids)->where('granted', false)->distinct()->count('pid');
                if ($blocked < 2) {
                    usleep(10000);
                }
            } while ($blocked < 2 && microtime(true) < $deadline);
            self::assertSame(2, $blocked);
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                self::assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $results[] = trim(preg_replace('/ready:\d+\s*/', '', $worker->getOutput()));
            }
            self::assertSame(['denied', 'ok'], $results);
            self::assertNull($invites[0]->fresh()->exchanged_at);
            self::assertNotNull($invites[1]->fresh()->exchanged_at);
            self::assertSame($invites[1]->id, app(InvitationExchangeGrants::class)->invitationId($request));
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }

    public function test_parallel_sessions_can_exchange_the_same_bearer_only_once(): void
    {
        $org = Organization::create(['name' => 'Race fixture']);
        config(['features.E0-02.defaults.organization_id' => $org->id]);
        $owner = User::create(['name' => 'Owner', 'email' => 'race-owner@example.test', 'password' => 'only-test-password']);
        $token = str_repeat('R', 64);
        $invite = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $owner->id), fn () => Invitation::create([
            'email' => 'race-member@example.test', 'role' => 'member', 'brand_ids' => [],
            'token_hash' => hash('sha256', $token), 'invited_by' => $owner->id, 'expires_at' => now()->addDay(),
        ]));
        $workers = [];
        $data = [];
        for ($i = 0; $i < 2; $i++) {
            $session = bin2hex(random_bytes(20));
            $request = Request::create('/invitations/open');
            $request->setLaravelSession(new Store('test', new ArraySessionHandler(120), $session));
            $data[] = ['org' => $org->id, 'session' => $session, 'epoch' => app(InvitationExchangeGrants::class)->beginDocument($request), 'hash' => hash('sha256', $token)];
        }
        DB::beginTransaction();
        try {
            DB::table('organizations')->where('id', $org->id)->lockForUpdate()->first();
            foreach ($data as $input) {
                $worker = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/invitation-exchange-worker.php'], base_path(), ['APP_ENV' => 'testing'], json_encode($input, JSON_THROW_ON_ERROR), 30);
                $worker->start();
                $workers[] = $worker;
            }
            $pids = [];
            $deadline = microtime(true) + 20;
            do {
                $pids = [];
                foreach ($workers as $worker) {
                    if (preg_match('/ready:(\d+)/', $worker->getOutput(), $match)) {
                        $pids[] = (int) $match[1];
                    }
                }
                if (count($pids) < 2) {
                    usleep(10000);
                }
            } while (count($pids) < 2 && microtime(true) < $deadline);
            self::assertCount(2, $pids);
            do {
                $blocked = DB::table('pg_locks')->whereIn('pid', $pids)->where('granted', false)->distinct()->count('pid');
                if ($blocked < 2) {
                    usleep(10000);
                }
            } while ($blocked < 2 && microtime(true) < $deadline);
            self::assertSame(2, $blocked);
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                self::assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $results[] = trim(preg_replace('/ready:\d+\s*/', '', $worker->getOutput()));
            }
            sort($results);
            self::assertSame(['denied', 'ok'], $results);
            self::assertNotNull($invite->fresh()->exchanged_at);
            self::assertSame(1, DB::table('invitation_exchange_sessions')->where('invitation_id', $invite->id)->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }
}
