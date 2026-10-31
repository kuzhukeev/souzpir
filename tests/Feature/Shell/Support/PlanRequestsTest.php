<?php

namespace Tests\Feature\Shell\Support;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Notifications\Contracts\NotificationDispatcher;
use App\Shell\Notifications\NotificationProcessor;
use App\Shell\Support\Contracts\PlanRequestCommand;
use App\Shell\Support\Contracts\PlanRequestDeliveryStatus;
use App\Shell\Support\Contracts\PlanRequestPort;
use App\Shell\Support\Contracts\PlanRequestResult;
use App\Shell\Support\TicketMail;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class PlanRequestsTest extends SupportTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $app->make(Kernel::class)->bootstrap();
        $connection = $app['db']->connection();
        $server = $connection->selectOne('select current_database() as db, current_user as role, inet_server_addr() as host, inet_server_port() as port, rolsuper, rolcreatedb, rolcreaterole from pg_roles where rolname = current_user');
        if (! $app->environment('testing') || $connection->getDatabaseName() !== 'souzpir_test_sp_plan_request'
            || ! $server || $server->db !== 'souzpir_test_sp_plan_request' || $server->role !== 'sp_plan_app'
            || $server->host !== '127.0.0.1' || (int) $server->port !== 55531 || $server->rolsuper || $server->rolcreatedb || $server->rolcreaterole) {
            throw new RuntimeException('Refusing plan request tests outside their isolated database.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Route::middleware(['web', 'auth', 'verified', 'tenant'])->post('/_test/plan-request', function (Request $request) {
            $input = $request->validate(['plan_id' => ['required', 'ulid'], 'submission_key' => ['required', 'ulid']]);
            $result = app(PlanRequestPort::class)->submit($request, new PlanRequestCommand($input['plan_id'], $input['submission_key']));

            return response()->json(['ticket_id' => $result->ticketId, 'created' => $result->created,
                'delivery_status' => $result->deliveryStatus->value], $result->created ? 201 : 200);
        });
    }

    private function plan(bool $public = true): string
    {
        $id = (string) Str::ulid();
        DB::table('plans')->insert(['id' => $id, 'code' => strtolower($id), 'name' => 'Тестовый тариф', 'is_public' => $public, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function submit(User $user, Organization $org, Brand $brand, Membership $member, PlanRequestCommand $command): PlanRequestResult
    {
        $request = Request::create('/billing/plans/request', 'POST');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => $user);

        return app(TenantContextRunner::class)->run(new TenantContext($org->id, $brand->id, $user->id, $member->id),
            fn () => app(PlanRequestPort::class)->submit($request, $command));
    }

    public function test_owner_creates_one_ticket_outbox_and_audit_and_receives_accurate_delivery_status(): void
    {
        [$owner, $org, $brand, , $member] = $this->cabinet();
        [$admin] = $this->member($org, 'admin', [$brand->id]);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->signIn($owner, $brand);
        $plan = $this->plan();
        $key = (string) Str::ulid();
        $result = $this->submit($owner, $org, $brand, $member, new PlanRequestCommand($plan, $key));
        self::assertTrue($result->created);
        self::assertSame(PlanRequestDeliveryStatus::NotConfigured, $result->deliveryStatus);
        $ticket = DB::table('tickets')->where('id', $result->ticketId)->sole();
        self::assertSame('plan_request', $ticket->type);
        self::assertSame($org->id, $ticket->organization_id);
        self::assertSame($owner->id, $ticket->user_id);
        self::assertStringContainsString($plan, $ticket->body);
        self::assertSame(0, DB::table('tickets')->where('type', 'support')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'billing.plan_request.created')->where('event_id', $result->ticketId)->count());
        $event = DB::table('outbox_events')->where('id', $result->ticketId)->sole();
        $envelope = json_decode($event->envelope, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['ticket_id' => $result->ticketId], $envelope['data']);
        self::assertSame($brand->id, $event->brand_id);
        self::assertStringNotContainsString('Тестовый тариф', $event->envelope);
        app(NotificationProcessor::class)->process($result->ticketId);
        self::assertSame([$owner->id], DB::table('cabinet_notifications')->where('event_key', 'shell.plan_request.created')->pluck('user_id')->all());
        self::assertNotContains($admin->id, DB::table('cabinet_notifications')->pluck('user_id')->all());
        self::assertNotContains($viewer->id, DB::table('cabinet_notifications')->pluck('user_id')->all());

        DB::table('plans')->where('id', $plan)->update(['is_public' => false]);
        $again = $this->submit($owner, $org, $brand, $member, new PlanRequestCommand(strtolower($plan), strtolower($key)));
        self::assertFalse($again->created);
        self::assertSame($result->ticketId, $again->ticketId);
        self::assertSame(PlanRequestDeliveryStatus::NotConfigured, $again->deliveryStatus);
        self::assertSame(1, DB::table('tickets')->count());
        self::assertSame(1, DB::table('outbox_events')->where('id', $result->ticketId)->count());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_wrong_role_brand_and_non_public_plan_are_rejected_without_writes(): void
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        [$admin, $adminMember] = $this->member($org, 'admin', [$brand->id]);
        $public = $this->plan();
        $private = $this->plan(false);
        $this->signIn($admin, $brand);
        try {
            $this->submit($admin, $org, $brand, $adminMember, new PlanRequestCommand($public, (string) Str::ulid()));
            self::fail('Admin must not manage billing.');
        } catch (AuthorizationException $error) {
            self::assertNotNull($error);
        }
        $this->signIn($owner, $brand);
        foreach ([[$brand, $private], [$other, $public]] as [$selected, $plan]) {
            try {
                $this->submit($owner, $org, $selected, $member, new PlanRequestCommand($plan, (string) Str::ulid()));
                self::fail('Private plan and mismatched session brand must fail.');
            } catch (HttpException $error) {
                self::assertContains($error->getStatusCode(), [403, 404]);
            }
        }
        self::assertSame(0, DB::table('tickets')->count());
        self::assertSame(0, DB::table('outbox_events')->count());
    }

    public function test_reused_key_with_different_plan_conflicts_and_enqueue_failure_rolls_back(): void
    {
        [$owner, $org, $brand, , $member] = $this->cabinet();
        $this->signIn($owner, $brand);
        $first = $this->plan();
        $second = $this->plan();
        $key = (string) Str::ulid();
        $this->submit($owner, $org, $brand, $member, new PlanRequestCommand($first, $key));
        try {
            $this->submit($owner, $org, $brand, $member, new PlanRequestCommand($second, $key));
            self::fail('A key cannot be reused for another request.');
        } catch (HttpException $error) {
            self::assertSame(409, $error->getStatusCode());
        }
        $this->mock(NotificationDispatcher::class)->shouldReceive('enqueue')->once()->andThrow(new RuntimeException('test dispatch failure'));
        try {
            $this->submit($owner, $org, $brand, $member, new PlanRequestCommand($second, (string) Str::ulid()));
            self::fail('Dispatch failure must abort the transaction.');
        } catch (RuntimeException $error) {
            self::assertSame('test dispatch failure', $error->getMessage());
        }
        self::assertSame(1, DB::table('tickets')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'billing.plan_request.created')->count());
        self::assertSame(1, DB::table('outbox_events')->count());
    }

    public function test_fake_delivery_is_uncertain_and_duplicate_does_not_resend(): void
    {
        [$owner, $org, $brand, , $member] = $this->cabinet();
        $this->signIn($owner, $brand);
        config(['features.E1-12.defaults.mail_enabled' => true, 'features.E1-12.defaults.smtp_enabled' => true,
            'features.E1-12.defaults.mailer' => 'smtp', 'features.E1-12.defaults.recipient' => 'support@example.test']);
        $command = new PlanRequestCommand($this->plan(), (string) Str::ulid());
        $first = $this->submit($owner, $org, $brand, $member, $command);
        $second = $this->submit($owner, $org, $brand, $member, $command);
        self::assertSame(PlanRequestDeliveryStatus::Uncertain, $first->deliveryStatus);
        self::assertSame(PlanRequestDeliveryStatus::Uncertain, $second->deliveryStatus);
        Mail::assertSent(TicketMail::class, 1);
        Mail::assertSent(TicketMail::class, fn (TicketMail $mail) => str_contains($mail->render(), 'Заявка на смену тарифа'));
        Http::assertNothingSent();
    }

    public function test_configured_but_disabled_support_delivery_is_not_reported_as_sent(): void
    {
        [$owner, $org, $brand, , $member] = $this->cabinet();
        $this->signIn($owner, $brand);
        config(['features.E1-12.defaults.recipient' => 'support@example.test', 'features.E1-12.defaults.mail_enabled' => false]);
        $result = $this->submit($owner, $org, $brand, $member, new PlanRequestCommand($this->plan(), (string) Str::ulid()));
        self::assertSame(PlanRequestDeliveryStatus::Disabled, $result->deliveryStatus);
        self::assertSame('disabled', DB::table('tickets')->where('id', $result->ticketId)->value('delivery_status'));
        Mail::assertNothingSent();
    }

    public function test_other_organization_cannot_receive_notification_and_archived_brand_suppresses_delivery(): void
    {
        [$owner, $org, $brand, , $member] = $this->cabinet();
        [$otherOwner, $otherOrg, $otherBrand, , $otherMember] = $this->cabinet();
        $this->signIn($owner, $brand);
        $plan = $this->plan();
        $first = $this->submit($owner, $org, $brand, $member, new PlanRequestCommand($plan, (string) Str::ulid()));
        $this->signIn($otherOwner, $otherBrand);
        $second = $this->submit($otherOwner, $otherOrg, $otherBrand, $otherMember, new PlanRequestCommand($plan, (string) Str::ulid()));
        app(NotificationProcessor::class)->process($first->ticketId);
        app(NotificationProcessor::class)->process($second->ticketId);
        self::assertSame([$owner->id], DB::table('cabinet_notifications')->where('organization_id', $org->id)->pluck('user_id')->all());
        self::assertSame([$otherOwner->id], DB::table('cabinet_notifications')->where('organization_id', $otherOrg->id)->pluck('user_id')->all());

        $third = $this->submit($otherOwner, $otherOrg, $otherBrand, $otherMember, new PlanRequestCommand($plan, (string) Str::ulid()));
        DB::table('brands')->where('id', $otherBrand->id)->update(['status' => 'archived']);
        app(NotificationProcessor::class)->process($third->ticketId);
        self::assertSame(0, DB::table('notification_deliveries')->where('event_id', $third->ticketId)->count());
    }

    public function test_revoked_session_is_rejected_before_creating_ticket(): void
    {
        [$owner, $org, $brand, , $member] = $this->cabinet();
        $this->signIn($owner, $brand);
        DB::table('auth_session_registry')->where('user_id', $owner->id)->update(['revoked_at' => now()]);
        try {
            $this->submit($owner, $org, $brand, $member, new PlanRequestCommand($this->plan(), (string) Str::ulid()));
            self::fail('A revoked session cannot create a request.');
        } catch (AuthenticationException $error) {
            self::assertNotNull($error);
        }
        self::assertSame(0, DB::table('tickets')->count());
        self::assertSame(0, DB::table('outbox_events')->count());
    }

    public function test_http_boundary_resolves_tenant_and_rejects_forged_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->signIn($owner, $brand);
        $input = ['plan_id' => $this->plan(), 'submission_key' => (string) Str::ulid()];
        $this->withHeader('X-Brand-Id', $other->id)->postJson('/_test/plan-request', $input)->assertNotFound();
        $this->flushHeaders()->postJson('/_test/plan-request', $input)->assertCreated()->assertJsonPath('delivery_status', 'not_configured');
        $this->postJson('/_test/plan-request', $input)->assertOk()->assertJsonPath('created', false);
        self::assertSame(1, DB::table('tickets')->where('organization_id', $org->id)->count());
        self::assertSame(1, DB::table('outbox_events')->where('brand_id', $brand->id)->count());
    }
}
