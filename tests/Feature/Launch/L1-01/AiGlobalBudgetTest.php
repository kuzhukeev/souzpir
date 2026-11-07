<?php

namespace Tests\Feature\Launch\L1_01;

use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiGateway;
use App\Contracts\Ai\AiRequest;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

final class AiGlobalBudgetTest extends TestCase
{
    use DatabaseMigrations;

    private string $organization;

    private string $brand;

    // Gateway's live driver verifies the call outside a database transaction.
    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = (string) Str::ulid();
        $this->brand = (string) Str::ulid();
        DB::table('organizations')->insert(['id' => $this->organization, 'name' => 'Test', 'status' => 'active', 'settings' => '{}']);
        DB::table('brands')->insert(['id' => $this->brand, 'organization_id' => $this->organization, 'name' => 'Test brand', 'status' => 'active', 'theme' => 'neutral',
            'settings' => json_encode(['ai_data_policy' => ['knowledge_public' => ['any']]], JSON_THROW_ON_ERROR)]);
        config(['runtime.ai_driver' => 'openrouter', 'features.E0-05.defaults.live_enabled' => true, 'features.E0-05.defaults.openrouter_key' => 'test-only']);
        Http::preventStrayRequests();
    }

    private function within(\Closure $callback): mixed
    {
        return app(TenantContextRunner::class)->run(new TenantContext($this->organization, $this->brand, source: 'job'), $callback);
    }

    private function context(string $key): AiContext
    {
        return new AiContext($this->organization, $this->brand, 'seller.live_draft', 'seller', 'brand', $this->brand, $key, ['knowledge_public']);
    }

    private function request(): AiRequest
    {
        return new AiRequest([['role' => 'user', 'content' => 'Привет']], 'anthropic/claude-sonnet-5.5', 0.5);
    }

    private function fakeOk(int $costMicros): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['model' => 'anthropic/claude-sonnet-5.5', 'choices' => [['finish_reason' => 'stop',
            'message' => ['content' => 'Ответ']]], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3, 'cost' => $costMicros / 1_000_000]], 200)]);
    }

    public function test_global_daily_limit_stops_before_http_and_without_reservation(): void
    {
        config(['features.L1-01.defaults.global_daily_usd' => 0.00001]);
        $this->fakeOk(1);
        $this->within(function (): void {
            try {
                app(AiGateway::class)->complete($this->request(), $this->context('daily-stop'));
                self::fail('Daily ceiling must stop the call.');
            } catch (LogicException $e) {
                self::assertSame('AI budget exhausted.', $e->getMessage());
            }
            self::assertSame(0, DB::table('ai_calls')->count());
        });
        Http::assertNothingSent();
    }

    public function test_global_monthly_limit_stops_before_http(): void
    {
        config(['features.L1-01.defaults.global_monthly_usd' => 0.00001]);
        $this->fakeOk(1);
        $this->within(function (): void {
            try {
                app(AiGateway::class)->complete($this->request(), $this->context('monthly-stop'));
                self::fail('Monthly ceiling must stop the call.');
            } catch (LogicException $e) {
                self::assertSame('AI budget exhausted.', $e->getMessage());
            }
        });
        Http::assertNothingSent();
    }

    public function test_eighty_percent_organization_threshold_fires_once_per_period(): void
    {
        // 50000 micros (one claude-sonnet-5.5 reservation) is exactly 80% of 62500.
        config(['features.L1-01.defaults.org_monthly_usd' => 0.0625]);
        $this->fakeOk(1);
        $this->within(function (): void {
            $first = app(AiGateway::class)->complete($this->request(), $this->context('threshold-1'));
            self::assertTrue($first->ok());
            $event = json_decode(DB::table('outbox_events')->value('envelope'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('ai.budget.threshold_reached', $event['type']);
            self::assertSame('0.8000', $event['data']['fraction']);
            $second = app(AiGateway::class)->complete($this->request(), $this->context('threshold-2'));
            self::assertTrue($second->ok());
            self::assertSame(1, DB::table('outbox_events')->count(), 'The threshold notice must not repeat within the same period.');
        });
    }

    public function test_uncertain_call_keeps_counting_toward_the_reservation(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response('provider error', 500)]);
        $this->within(function (): void {
            try {
                app(AiGateway::class)->complete($this->request(), $this->context('uncertain-1'));
            } catch (LogicException) {
                // Transport-level outage: the call is left uncertain, not retried here.
            }
            $call = DB::table('ai_calls')->where('status', 'uncertain')->first();
            self::assertNotNull($call, 'A provider outage must leave the call uncertain, not failed.');
            $reservation = DB::table('ai_live_reservations')->where('ai_call_id', $call->id)->first();
            self::assertNotNull($reservation);
            self::assertSame(50000, (int) $reservation->reserved_micros);
            self::assertNull($reservation->spent_micros);
            self::assertFalse((bool) $reservation->released);
        });
    }

    public function test_set_org_command_overrides_the_default_and_validates_input(): void
    {
        self::assertSame(1, Artisan::call('ai:budget:set-org', ['organization' => 'not-a-ulid', 'usd' => '5']));
        self::assertSame(1, Artisan::call('ai:budget:set-org', ['organization' => (string) Str::ulid(), 'usd' => '5']));
        self::assertSame(0, Artisan::call('ai:budget:set-org', ['organization' => $this->organization, 'usd' => '0.00001']));
        self::assertSame('0.000010', (string) DB::table('ai_org_budget_overrides')->where('organization_id', $this->organization)->value('monthly_usd'));
        $this->fakeOk(1);
        $this->within(function (): void {
            try {
                app(AiGateway::class)->complete($this->request(), $this->context('override-stop'));
                self::fail('The overridden organization ceiling must stop the call.');
            } catch (LogicException $e) {
                self::assertSame('AI budget exhausted.', $e->getMessage());
            }
        });
        Http::assertNothingSent();
    }

    public function test_ai_budget_command_prints_daily_monthly_and_organization_usage(): void
    {
        $this->fakeOk(12);
        $this->within(fn () => app(AiGateway::class)->complete($this->request(), $this->context('report-1')));
        Artisan::call('ai:budget');
        $output = Artisan::output();
        self::assertStringContainsString('Глобально сегодня', $output);
        self::assertStringContainsString('Глобально за месяц', $output);
        self::assertStringContainsString('Организация '.$this->organization, $output);
    }
}
