<?php

namespace Tests\Feature\Shell\Team;

use App\Models\Membership;
use App\Models\Organization;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Team\InvitationMail;
use App\Shell\Team\Models\Invitation;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\PendingMail;
use Illuminate\Mail\SentMessage;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class TeamSecurityTest extends TeamTestCase
{
    public function test_restricted_admin_cannot_read_foreign_brand_or_strip_hidden_grants(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$admin, $adminMembership] = $this->member($org, 'admin', [$brand->id]);
        [, $shared] = $this->member($org, 'member', [$brand->id, $other->id]);
        [, $hidden] = $this->member($org, 'member', [$other->id]);
        [, $visible] = $this->member($org, 'member', [$brand->id]);
        $foreignOwner = $this->user();
        $foreign = Organization::create(['name' => 'Foreign fixture', 'owner_user_id' => $foreignOwner->id]);
        $foreignMember = app(TenantContextRunner::class)->run(new TenantContext($foreign->id, source: 'job'),
            fn () => Membership::create(['user_id' => $foreignOwner->id, 'role' => 'owner']));
        $foreignInvitation = app(TenantContextRunner::class)->run(new TenantContext($foreign->id, source: 'job'),
            fn () => Invitation::create(['email' => 'foreign@example.test', 'role' => 'admin', 'brand_ids' => [],
                'invited_by' => $foreignOwner->id, 'token_hash' => hash('sha256', 'foreign-fixture'), 'expires_at' => now()->addDays(7)]));
        $this->signIn($admin, $brand);
        $this->get('/team')->assertInertia(fn (Assert $page) => $page->where('team.can_grant_all_brands', false)->has('team.brands', 1));
        $this->get('/team/'.$shared->id)->assertInertia(fn (Assert $page) => $page->where('team.member.can_edit', false)
            ->where('team.member.has_hidden_brands', true)->where('team.member.brand_ids', [$brand->id]));
        self::assertSame(404, $this->get('/team/'.$hidden->id)->status());
        self::assertSame(404, $this->get('/team/'.$foreignMember->id)->status());
        self::assertSame(404, $this->postJson('/team/'.$foreignMember->id.'/disable')->status());
        self::assertSame(404, $this->get('/team?brand_id='.$other->id)->status());
        self::assertSame(404, $this->postJson('/team/invitations/'.$foreignInvitation->id.'/resend')->status());
        self::assertSame(404, $this->postJson('/team/invitations/'.$foreignInvitation->id.'/revoke')->status());
        self::assertSame(403, $this->patchJson('/team/'.$shared->id, ['role' => 'viewer', 'brand_ids' => [$brand->id]])->status());
        self::assertSame(403, $this->postJson('/team/'.$adminMembership->id.'/disable')->status());
        self::assertSame(422, $this->patchJson('/team/'.$visible->id, ['role' => 'admin', 'brand_ids' => []])->status());
        foreach ([['role' => 'admin', 'brand_ids' => []], ['role' => 'viewer', 'brand_ids' => [$other->id]],
            ['role' => 'viewer', 'brand_ids' => []], ['role' => 'owner', 'brand_ids' => [$brand->id]],
            ['role' => 'viewer', 'brand_ids' => [Str::ulid()->toBase32()]]] as $grant) {
            self::assertSame(422, $this->postJson('/team/invitations', ['email' => 'forbidden@example.test', ...$grant])->status());
        }
        self::assertSame(0, Invitation::withoutGlobalScopes()->where('organization_id', $org->id)->count());
        self::assertSame(0, DB::table('audit_logs')->where('action', 'like', 'team.%')->count());
        Mail::assertNothingSent();
    }

    public function test_disable_nowait_contention_is_atomic_then_invalidates_session_and_remember_token(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        [$target, $member] = $this->member($org, 'viewer', [$brand->id]);
        $target->forceFill(['remember_token' => Str::random(60)])->save();
        $target->refresh();
        $remember = $target->getRememberToken();
        $version = $target->auth_version;
        $session = new Store('team-target-session', new ArraySessionHandler(120));
        $session->start();
        $session->put('auth.version', $version);
        $targetRequest = Request::create('/team', 'GET');
        $targetRequest->setLaravelSession($session);
        $targetRequest->setUserResolver(fn () => $target);
        app(AuthSessionRegistry::class)->issue($targetRequest, $target, $version);
        $this->signIn($owner, $brand);
        config(['database.connections.team_lock_probe' => config('database.connections.pgsql')]);
        $probe = DB::connection('team_lock_probe');
        $probe->beginTransaction();
        try {
            $probe->select('SELECT id FROM users WHERE id = ? FOR NO KEY UPDATE', [$target->id]);
            $started = microtime(true);
            $response = $this->postJson('/team/'.$member->id.'/disable');
            self::assertSame(409, $response->status());
            self::assertLessThan(5.0, microtime(true) - $started);
            self::assertSame('active', $member->fresh()->status);
            self::assertSame($version, $target->fresh()->auth_version);
            self::assertSame(0, DB::table('audit_logs')->where('action', 'team.member.disabled')->count());
        } finally {
            $probe->rollBack();
            DB::purge('team_lock_probe');
        }
        self::assertSame(200, $this->postJson('/team/'.$member->id.'/disable')->status());
        self::assertSame('disabled', $member->fresh()->status);
        self::assertSame($version + 1, $target->fresh()->auth_version);
        self::assertFalse(hash_equals($remember, $target->fresh()->getRememberToken()));
        self::assertSame(1, DB::table('audit_logs')->where('action', 'team.member.disabled')->count());
        try {
            app(AuthSessionRegistry::class)->withinActive($targetRequest, fn () => self::fail('Disabled session was accepted.'));
            self::fail('Disabled session was accepted.');
        } catch (AuthenticationException) {
            self::assertTrue(true);
        }
        self::assertSame(200, $this->get('/team')->status());
        self::assertSame(422, $this->postJson('/team/invitations', ['email' => $target->email, 'role' => 'viewer', 'brand_ids' => [$brand->id]])->status());
    }

    public function test_disabled_delivery_and_log_mailer_never_receive_invitation_secrets(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        $first = $this->invitation('disabled@example.test', 'viewer', [$brand->id]);
        self::assertSame('disabled', $first['delivery_status']);
        config(['features.E1-06.defaults.mail_enabled' => true, 'features.E1-06.defaults.smtp_enabled' => true, 'features.E1-06.defaults.mailer' => 'log']);
        $second = $this->invitation('no-log@example.test', 'admin', []);
        self::assertSame('disabled', $second['delivery_status']);
        Mail::assertNothingSent();
        self::assertFalse(str_contains(json_encode(DB::table('audit_logs')->get()), $this->token($first)));
        self::assertFalse(str_contains(json_encode(DB::table('audit_logs')->get()), $this->token($second)));
    }

    public function test_mail_fake_without_receipt_is_uncertain_and_link_is_returned_only_once(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        config(['features.E1-06.defaults.mail_enabled' => true, 'features.E1-06.defaults.smtp_enabled' => true, 'features.E1-06.defaults.mailer' => 'smtp']);
        $issued = $this->invitation('fake-mail@example.test', 'viewer', [$brand->id]);
        self::assertSame('uncertain', $issued['delivery_status']);
        Mail::assertSent(InvitationMail::class, fn ($mail) => $mail->hasTo('fake-mail@example.test') && str_contains($mail->render(), '/invitations/open#'.$this->token($issued)));
        self::assertFalse(str_contains($this->get('/team')->getContent(), $this->token($issued)));
        self::assertFalse(str_contains(json_encode(session()->all()), $this->token($issued)));
    }

    public function test_only_real_smtp_receipt_reports_sent_and_transport_runs_after_commit(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        config(['features.E1-06.defaults.mail_enabled' => true, 'features.E1-06.defaults.smtp_enabled' => true, 'features.E1-06.defaults.mailer' => 'smtp']);
        $mailer = Mockery::mock(Mailer::class);
        $pending = Mockery::mock(PendingMail::class);
        Mail::shouldReceive('mailer')->once()->with('smtp')->andReturn($mailer);
        $mailer->shouldReceive('to')->once()->with('receipt@example.test')->andReturn($pending);
        $pending->shouldReceive('send')->once()->with(Mockery::type(InvitationMail::class))->andReturnUsing(function () {
            self::assertSame(0, DB::transactionLevel());
            self::assertSame(1, Invitation::withoutGlobalScopes()->where('email', 'receipt@example.test')->count());

            return new SentMessage(new SymfonySentMessage((new Email)->from('sender@example.test')->to('receipt@example.test')->text('Offline fixture'),
                new Envelope(new Address('sender@example.test'), [new Address('receipt@example.test')])));
        });
        $issued = $this->invitation('receipt@example.test', 'viewer', [$brand->id]);
        self::assertSame('sent', $issued['delivery_status']);
        self::assertSame('mail', $issued['delivery']);
        self::assertArrayNotHasKey('invitation_url', $issued);
    }

    public function test_smtp_failure_is_sanitized_without_logging_exception_body(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        config(['features.E1-06.defaults.mail_enabled' => true, 'features.E1-06.defaults.smtp_enabled' => true, 'features.E1-06.defaults.mailer' => 'smtp']);
        Log::spy();
        $mailer = Mockery::mock(Mailer::class);
        $pending = Mockery::mock(PendingMail::class);
        Mail::shouldReceive('mailer')->once()->with('smtp')->andReturn($mailer);
        $mailer->shouldReceive('to')->once()->with('failure@example.test')->andReturn($pending);
        $pending->shouldReceive('send')->once()->andThrow(new RuntimeException('SENSITIVE_SMTP_DIAGNOSTIC'));
        $issued = $this->invitation('failure@example.test', 'viewer', [$brand->id]);
        self::assertSame('uncertain', $issued['delivery_status']);
        self::assertFalse(str_contains(json_encode($issued), 'SENSITIVE_SMTP_DIAGNOSTIC'));
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
    }

    public function test_audit_failure_rolls_back_invitation_and_sends_nothing(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        app()->instance(AuditWriter::class, new class implements AuditWriter
        {
            public function record(TenantContext $context, AuditEntry $entry): void
            {
                throw new RuntimeException('Audit fixture failure.');
            }
        });
        self::assertSame(500, $this->postJson('/team/invitations', ['email' => 'rollback@example.test', 'role' => 'viewer', 'brand_ids' => [$brand->id]])->status());
        self::assertSame(0, Invitation::withoutGlobalScopes()->count());
        self::assertSame(0, DB::table('audit_logs')->where('action', 'team.invitation.created')->count());
        Mail::assertNothingSent();
    }
}
