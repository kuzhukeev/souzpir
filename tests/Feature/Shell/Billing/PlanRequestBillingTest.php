<?php

namespace Tests\Feature\Shell\Billing;

use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Billing\SubmissionScope;
use App\Shell\Notifications\Contracts\NotificationDispatcher;
use App\Shell\Notifications\NotificationProcessor;
use App\Shell\Support\Contracts\PlanRequestCommand;
use App\Shell\Support\Contracts\PlanRequestPort;
use App\Shell\Support\Contracts\PlanRequestResult;
use App\Shell\Support\PlanRequests;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Shell\Support\Fixtures\IsolatedPlanTestDatabase;
use Tests\Feature\Shell\Support\SupportTestCase;

final class PlanRequestBillingTest extends SupportTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $app->make(Kernel::class)->bootstrap();
        IsolatedPlanTestDatabase::assert($app);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        // Explicitly prohibit actual external delivery even if local operator settings differ.
        config(['features.E1-12.defaults.recipient' => null, 'features.E1-12.defaults.mail_enabled' => false,
            'features.E1-12.defaults.smtp_enabled' => false, 'features.E0-04.defaults.external_delivery' => false]);
    }

    public function test_http_submit_persists_ticket_audit_and_notification_and_replays_hidden_plan(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$admin] = $this->member($org, 'admin', [$brand->id]);
        $this->signIn($owner, $brand);
        $plan = $this->plan();
        $key = (string) Str::ulid();
        $first = $this->postJson('/billing/plans/request', ['plan_id' => $plan, 'submission_key' => $key], $this->scopeHeaders($org, $owner, $brand))
            ->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.created', true)
            ->assertJsonPath('data.delivery_status', 'not_configured');
        $ticket = $first->json('data.ticket_id');
        self::assertTrue(Str::isUlid($first->json('data.next_submission_key')));
        self::assertNotSame($key, $first->json('data.next_submission_key'));
        $this->assertDatabaseHas('tickets', ['id' => $ticket, 'organization_id' => $org->id, 'user_id' => $owner->id, 'type' => 'plan_request']);
        $this->assertDatabaseHas('audit_logs', ['subject_id' => $ticket, 'action' => 'billing.plan_request.created']);
        $event = DB::table('outbox_events')->where('id', $ticket)->sole();
        self::assertSame(['ticket_id' => $ticket], json_decode($event->envelope, true, flags: JSON_THROW_ON_ERROR)['data']);
        app(NotificationProcessor::class)->process($ticket);
        self::assertSame([$owner->id], DB::table('cabinet_notifications')->where('event_key', 'shell.plan_request.created')->pluck('user_id')->all());
        self::assertNotContains($admin->id, DB::table('cabinet_notifications')->pluck('user_id')->all());
        DB::table('plans')->where('id', $plan)->update(['is_public' => false]);
        $this->postJson('/billing/plans/request', ['plan_id' => strtolower($plan), 'submission_key' => strtolower($key)], $this->scopeHeaders($org, $owner, $brand))
            ->assertOk()->assertJsonPath('data.created', false)->assertJsonPath('data.ticket_id', $ticket)->assertJsonPath('data.delivery_status', 'not_configured');
        self::assertSame(1, DB::table('tickets')->count());
        self::assertSame(1, DB::table('outbox_events')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'billing.plan_request.created')->count());
        $this->signIn($owner, $other)->postJson('/billing/plans/request', ['plan_id' => $plan, 'submission_key' => $key], $this->scopeHeaders($org, $owner, $other))->assertConflict();
        self::assertSame(1, DB::table('tickets')->count());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_roles_invalid_or_private_plan_and_changed_key_payload_are_rejected(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$admin] = $this->member($org, 'admin', [$brand->id]);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $public = $this->plan();
        $second = $this->plan();
        $private = $this->plan(false);
        $input = ['plan_id' => $public, 'submission_key' => (string) Str::ulid()];
        foreach ([$admin, $viewer] as $actor) {
            $this->signIn($actor, $brand)->postJson('/billing/plans/request', $input, $this->scopeHeaders($org, $actor, $brand))->assertForbidden();
        }
        $this->signIn($owner, $brand);
        $headers = $this->scopeHeaders($org, $owner, $brand);
        $this->postJson('/billing/plans/request', ['plan_id' => $private, 'submission_key' => (string) Str::ulid()], $headers)->assertNotFound();
        $this->postJson('/billing/plans/request', ['plan_id' => (string) Str::ulid(), 'submission_key' => (string) Str::ulid()], $headers)->assertNotFound();
        $this->postJson('/billing/plans/request', ['plan_id' => 'invalid', 'submission_key' => 'invalid'], $headers)->assertUnprocessable();
        $this->withHeader('X-Brand-Id', $other->id)->postJson('/billing/plans/request', $input, $headers)->assertNotFound();
        $this->flushHeaders()->postJson('/billing/plans/request', $input, $headers)->assertCreated();
        $this->postJson('/billing/plans/request', ['plan_id' => $second, 'submission_key' => $input['submission_key']], $headers)->assertConflict();
        self::assertSame(1, DB::table('tickets')->count());
        self::assertSame(1, DB::table('outbox_events')->count());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_owner_and_brand_scoped_submission_props_are_opaque_and_stable(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$admin] = $this->member($org, 'admin', [$brand->id]);
        $this->signIn($owner, $brand);
        $one = $this->get('/billing/plans')->assertOk()->viewData('page')['props'];
        self::assertTrue($one['requestEnabled']);
        self::assertTrue(Str::isUlid($one['submissionKey']));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $one['submissionScope']);
        self::assertStringNotContainsString($org->id, $one['submissionScope']);
        self::assertStringNotContainsString($owner->id, $one['submissionScope']);
        $again = $this->get('/billing/plans')->assertOk()->viewData('page')['props'];
        self::assertSame($one['submissionScope'], $again['submissionScope']);
        $different = $this->signIn($owner, $other)->get('/billing/plans')->assertOk()->viewData('page')['props'];
        self::assertNotSame($one['submissionScope'], $different['submissionScope']);
        $this->signIn($admin, $brand)->get('/billing')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('requestEnabled', false)->where('submissionKey', null)->where('submissionScope', null));
    }

    public function test_identical_submission_key_is_isolated_across_organizations_and_actors(): void
    {
        [$ownerA, $orgA, $brandA] = $this->cabinet();
        [$ownerB, $orgB, $brandB] = $this->cabinet();
        $plan = $this->plan();
        $input = ['plan_id' => $plan, 'submission_key' => (string) Str::ulid()];
        config(['features.E0-02.defaults.organization_id' => $orgA->id]);
        $ticketA = $this->signIn($ownerA, $brandA)->postJson('/billing/plans/request', $input, $this->scopeHeaders($orgA, $ownerA, $brandA))->assertCreated()->json('data.ticket_id');
        config(['features.E0-02.defaults.organization_id' => $orgB->id]);
        $ticketB = $this->signIn($ownerB, $brandB)->postJson('/billing/plans/request', $input, $this->scopeHeaders($orgB, $ownerB, $brandB))->assertCreated()->json('data.ticket_id');
        self::assertNotSame($ticketA, $ticketB);
        $this->assertDatabaseHas('tickets', ['id' => $ticketA, 'organization_id' => $orgA->id, 'user_id' => $ownerA->id]);
        $this->assertDatabaseHas('tickets', ['id' => $ticketB, 'organization_id' => $orgB->id, 'user_id' => $ownerB->id]);
        $this->postJson('/billing/plans/request', $input, $this->scopeHeaders($orgB, $ownerB, $brandB))->assertOk()->assertJsonPath('data.ticket_id', $ticketB);
        app(NotificationProcessor::class)->process($ticketA);
        app(NotificationProcessor::class)->process($ticketB);
        self::assertSame([$ownerA->id], DB::table('cabinet_notifications')->where('organization_id', $orgA->id)->pluck('user_id')->all());
        self::assertSame([$ownerB->id], DB::table('cabinet_notifications')->where('organization_id', $orgB->id)->pluck('user_id')->all());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_revoked_session_cannot_submit(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        $plan = $this->plan();
        DB::table('auth_session_registry')->where('user_id', $owner->id)->update(['revoked_at' => now()]);
        $this->postJson('/billing/plans/request', ['plan_id' => $plan, 'submission_key' => (string) Str::ulid()], $this->scopeHeaders($org, $owner, $brand))->assertUnauthorized();
        self::assertSame(0, DB::table('tickets')->count());
        self::assertSame(0, DB::table('outbox_events')->count());
        Mail::assertNothingSent();
    }

    public function test_stale_page_cannot_create_request_for_newly_selected_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->signIn($owner, $brand);
        $oldScope = $this->scopeHeaders($org, $owner, $brand);
        $input = ['plan_id' => $this->plan(), 'submission_key' => (string) Str::ulid()];
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->postJson('/billing/plans/request', $input, $oldScope)->assertConflict();
        $this->postJson('/billing/plans/request', $input)->assertConflict();
        self::assertSame(0, DB::table('tickets')->count());
        $this->postJson('/billing/plans/request', $input, $this->scopeHeaders($org, $owner, $other))->assertCreated();
        $event = DB::table('outbox_events')->sole();
        self::assertSame($other->id, $event->brand_id);
        Mail::assertNothingSent();
    }

    public function test_stale_page_cannot_submit_after_owner_changes_on_same_brand(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        [$nextOwner, $nextMembership] = $this->member($org, 'admin', [$brand->id]);
        $oldScope = $this->scopeHeaders($org, $owner, $brand);
        DB::transaction(function () use ($org, $membership, $nextOwner, $nextMembership): void {
            DB::table('memberships')->where('id', $nextMembership->id)->update(['role' => 'owner']);
            DB::table('organizations')->where('id', $org->id)->update(['owner_user_id' => $nextOwner->id]);
            DB::table('memberships')->where('id', $membership->id)->update(['role' => 'admin']);
        });
        $this->signIn($nextOwner, $brand);
        $input = ['plan_id' => $this->plan(), 'submission_key' => (string) Str::ulid()];
        $this->postJson('/billing/plans/request', $input, $oldScope)->assertConflict();
        self::assertSame(0, DB::table('tickets')->count());
        $this->postJson('/billing/plans/request', $input, $this->scopeHeaders($org, $nextOwner, $brand))->assertCreated();
        $this->assertDatabaseHas('tickets', ['organization_id' => $org->id, 'user_id' => $nextOwner->id]);
    }

    public function test_session_brand_change_between_preflight_and_port_cannot_retarget_request(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->signIn($owner, $brand);
        $delegate = app(PlanRequests::class);
        $this->app->instance(PlanRequestPort::class, new class($delegate, $other->id) implements PlanRequestPort
        {
            public function __construct(private PlanRequests $delegate, private string $otherBrand) {}

            public function submit(Request $request, PlanRequestCommand $command): PlanRequestResult
            {
                // Simulate a session change after the controller's preflight, before the port locks it.
                $request->session()->put('brand_id', $this->otherBrand);

                return $this->delegate->submit($request, $command);
            }
        });
        $this->postJson('/billing/plans/request', ['plan_id' => $this->plan(), 'submission_key' => (string) Str::ulid()], $this->scopeHeaders($org, $owner, $brand))->assertForbidden();
        self::assertSame(0, DB::table('tickets')->count());
        self::assertSame(0, DB::table('outbox_events')->count());
        Mail::assertNothingSent();
    }

    public function test_notification_failure_rolls_back_ticket_and_audit_without_external_delivery(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        $plan = $this->plan();
        $this->mock(NotificationDispatcher::class)->shouldReceive('enqueue')->once()->andThrow(new \RuntimeException('private fixture error'));
        $this->withoutExceptionHandling();
        try {
            $this->postJson('/billing/plans/request', ['plan_id' => $plan, 'submission_key' => (string) Str::ulid()], $this->scopeHeaders($org, $owner, $brand));
            self::fail('Failure must abort the transaction.');
        } catch (\RuntimeException $error) {
            self::assertSame('Не удалось создать заявку. Повторите ту же попытку.', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
        self::assertSame(0, DB::table('tickets')->count());
        self::assertSame(0, DB::table('outbox_events')->count());
        self::assertSame(0, DB::table('audit_logs')->where('action', 'billing.plan_request.created')->count());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_audit_failure_rolls_back_ticket_and_notification(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        $plan = $this->plan();
        $this->mock(AuditWriter::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('private audit error'));
        $this->withoutExceptionHandling();
        try {
            $this->postJson('/billing/plans/request', ['plan_id' => $plan, 'submission_key' => (string) Str::ulid()], $this->scopeHeaders($org, $owner, $brand));
            self::fail('Audit failure must abort the transaction.');
        } catch (\RuntimeException $error) {
            self::assertSame('Не удалось создать заявку. Повторите ту же попытку.', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
        self::assertSame(0, DB::table('tickets')->count());
        self::assertSame(0, DB::table('outbox_events')->count());
        Mail::assertNothingSent();
    }

    private function plan(bool $public = true): string
    {
        $id = (string) Str::ulid();
        DB::table('plans')->insert(['id' => $id, 'code' => strtolower($id), 'name' => 'Тестовый тариф',
            'is_public' => $public, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function scopeHeaders(object $organization, object $user, object $brand): array
    {
        return ['X-Billing-Context' => SubmissionScope::for(new TenantContext($organization->id, $brand->id, $user->id))];
    }
}
