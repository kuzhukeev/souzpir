<?php

namespace Tests\Feature\Shell\Integrations\Webhooks;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Brands\Contracts\DomainDnsResolver;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Commits are real: unlike RefreshDatabase, DatabaseMigrations does not wrap the test in a transaction. */
final class WebhookCommitTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        config(['queue.default' => 'sync', 'features.E1-10.defaults.webhook_delivery' => true]);
        $this->app->instance(DomainDnsResolver::class, new CommitTestDns);
    }

    public function test_test_action_sends_fake_http_only_after_real_commit(): void
    {
        $this->signInAndCreateWebhook();
        self::assertSame(0, DB::transactionLevel());
        $hook = DB::table('webhooks')->first();
        $secret = Crypt::decryptString($hook->secret);
        $levelAtSend = null;
        Http::fake(function (ClientRequest $request) use (&$levelAtSend) {
            $levelAtSend = DB::transactionLevel();

            return Http::response('', 204);
        });
        $this->post('/integrations/api/webhooks/'.$hook->id.'/test')->assertRedirect('/integrations/api');
        self::assertSame(0, $levelAtSend);
        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://public.org/hook'
            && $request->hasHeader('X-Souzpir-Signature', 'sha256='.hash_hmac('sha256', $request->body(), $secret)));
        $this->assertDatabaseHas('webhook_deliveries', ['webhook_id' => $hook->id, 'status' => 'sent', 'attempts' => 1, 'status_code' => 204]);
        $this->assertDatabaseHas('audit_logs', ['subject_id' => $hook->id, 'action' => 'integrations.webhook.test_requested']);
    }

    public function test_rollback_discards_delivery_and_never_sends(): void
    {
        $this->signInAndCreateWebhook();
        $hook = DB::table('webhooks')->first();
        Http::fake();
        DB::beginTransaction();
        try {
            $this->post('/integrations/api/webhooks/'.$hook->id.'/test')->assertRedirect('/integrations/api');
            $this->assertDatabaseHas('webhook_deliveries', ['webhook_id' => $hook->id, 'status' => 'pending']);
            Http::assertNothingSent();
        } finally {
            DB::rollBack();
        }
        self::assertSame(0, DB::transactionLevel());
        $this->assertDatabaseMissing('webhook_deliveries', ['webhook_id' => $hook->id]);
        $this->assertDatabaseMissing('audit_logs', ['subject_id' => $hook->id, 'action' => 'integrations.webhook.test_requested']);
        Http::assertNothingSent();
    }

    private function signInAndCreateWebhook(): void
    {
        $user = User::create(['name' => 'Test actor', 'email' => Str::lower(Str::random(12)).'@example.test', 'password' => 'test-only-password']);
        $user->forceFill(['email_verified_at' => now()])->save();
        $organization = Organization::create(['name' => 'Test cabinet', 'owner_user_id' => $user->id]);
        $brand = app(TenantContextRunner::class)->run(new TenantContext($organization->id, source: 'job'), function () use ($user): Brand {
            Membership::create(['user_id' => $user->id, 'role' => 'owner']);

            return Brand::create(['name' => 'Test brand', 'theme' => 'neutral']);
        });
        config(['features.E0-02.defaults.organization_id' => $organization->id]);
        $user->refresh();
        $this->actingAs($user);
        $this->startSession();
        session()->invalidate();
        $this->withSession(['auth.version' => $user->auth_version, 'brand_id' => $brand->id]);
        $request = Request::create('/', 'GET');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => $user);
        app(AuthSessionRegistry::class)->issue($request, $user, $user->auth_version);
        $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
        $this->post('/integrations/api/webhooks', ['url' => 'https://public.org/hook', 'events' => ['seller.lead.created']])->assertSessionHasNoErrors();
    }
}

final class CommitTestDns implements DomainDnsResolver
{
    public function addresses(string $host): array
    {
        return ['8.8.8.8'];
    }

    public function txtRecords(string $host): array
    {
        return [];
    }
}
