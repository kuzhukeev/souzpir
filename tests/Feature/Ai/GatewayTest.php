<?php

namespace Tests\Feature\Ai;

use App\Ai\CanonicalJson;
use App\Ai\ContactMasker;
use App\Ai\Drivers\OpenRouterDriver;
use App\Ai\Drivers\Rejected;
use App\Ai\Gateway;
use App\Ai\Money;
use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiGateway;
use App\Contracts\Ai\AiRequest;
use App\Contracts\Ai\AiResult;
use App\Shell\Notifications\Contracts\NotificationDispatcher;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Database\Seeders\Ai\PromptSetsSeeder;
use Database\Seeders\Ai\ProviderSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

final class GatewayTest extends TestCase
{
    use DatabaseMigrations;

    private string $organization;

    private string $brand;

    private string $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = (string) Str::ulid();
        $this->brand = (string) Str::ulid();
        DB::table('organizations')->insert(['id' => $this->organization, 'name' => 'Test', 'status' => 'active', 'settings' => '{}']);
        DB::table('brands')->insert(['id' => $this->brand, 'organization_id' => $this->organization, 'name' => 'Test brand', 'status' => 'active', 'theme' => 'neutral', 'settings' => '{}']);
        $this->fixtures = storage_path('app/ai-fixtures-'.Str::random(12));
        mkdir($this->fixtures, 0700, true);
        config(['runtime.ai_driver' => 'fake', 'features.E0-05.defaults.fixture_directory' => $this->fixtures]);
    }

    public function test_contact_boundary_rejects_before_reservation_and_transport(): void
    {
        Http::fake();
        $examples = [
            'user at example.test',
            'user собака example . test',
            '+7;999;1234567',
            '+7|999|123|45|67',
            'mail hidden\u0040example.test',
        ];
        $this->within(function () use ($examples): void {
            foreach ($examples as $index => $message) {
                try {
                    app(AiGateway::class)->complete(
                        new AiRequest([['role' => 'user', 'content' => $message]], 'google/gemini-2.5-flash', 0.5),
                        $this->context('privacy-boundary-'.$index)
                    );
                    self::fail('Unsafe input must be rejected.');
                } catch (\InvalidArgumentException $error) {
                    self::assertSame('AI data could not be safely prepared.', $error->getMessage());
                    self::assertNull($error->getPrevious());
                    self::assertStringNotContainsString($message, (string) $error);
                    self::assertStringNotContainsString($message, json_encode($error->getTrace(), JSON_THROW_ON_ERROR));
                }
            }
            self::assertSame(0, DB::table('ai_calls')->count());
            self::assertSame(0, DB::table('ai_budgets')->count());
            Http::assertNothingSent();
        });
    }

    public function test_budget_and_driver_errors_do_not_expose_original_request_in_trace(): void
    {
        $this->budget(1000);
        config(['features.E0-05.defaults.reservation_micros' => ['google/gemini-2.5-flash' => 100]]);
        DB::table('ai_budgets')->where('organization_id', $this->organization)->update(['limit_units' => '1']);
        $sensitive = 'synthetic-trace@example.test';
        $request = new AiRequest([['role' => 'user', 'content' => $sensitive]], 'google/gemini-2.5-flash', 0.0);
        $this->within(function () use ($request, $sensitive): void {
            try {
                app(AiGateway::class)->complete($request, $this->context('trace-budget'));
                self::fail('Budget rejection expected.');
            } catch (LogicException $error) {
                self::assertStringContainsString('approved conversion', $error->getMessage());
                self::assertStringNotContainsString($sensitive, json_encode($error->getTrace(), JSON_THROW_ON_ERROR));
            }
        });
        DB::table('ai_budgets')->where('organization_id', $this->organization)->update(['limit_units' => null]);
        $witness = 'synthetic-private-witness';
        $request = new AiRequest([['role' => 'user', 'content' => $witness]], 'google/gemini-2.5-flash', 0.0);
        $this->within(function () use ($request, $witness): void {
            try {
                app(AiGateway::class)->complete($request, $this->context('trace-driver'));
                self::fail('Missing fake fixture expected.');
            } catch (Rejected $error) {
                self::assertStringNotContainsString($witness, (string) $error);
                self::assertStringNotContainsString($witness, json_encode($error->getTrace(), JSON_THROW_ON_ERROR));
            }
        });
    }

    public function test_empty_schema_objects_survive_fake_gateway_and_remain_distinct_for_idempotency(): void
    {
        Http::fake();
        $format = ['type' => 'json_schema', 'json_schema' => ['name' => 'result', 'schema' => ['type' => 'object', 'properties' => new \stdClass]]];
        $tools = [['type' => 'function', 'function' => ['name' => 'search', 'parameters' => ['type' => 'object', 'properties' => new \stdClass]]]];
        $request = new AiRequest([['role' => 'user', 'content' => 'Привет']], 'google/gemini-2.5-flash', 0.5, $format, $tools);
        $this->fixture((new ContactMasker)->request($request->toArray()), 7);
        $this->within(function () use ($request, $format, $tools): void {
            $context = $this->context('empty-object-schema');
            $first = app(AiGateway::class)->complete($request, $context);
            $second = app(AiGateway::class)->complete($request, $context);
            self::assertSame('Ответ', $first->content);
            self::assertSame($first->aiCallId, $second->aiCallId);
            self::assertSame(1, DB::table('ai_calls')->count());
            $tools[0]['function']['parameters']['properties'] = [];
            $different = new AiRequest($request->messages, $request->model, $request->temperature, $format, $tools);
            try {
                app(AiGateway::class)->complete($different, $context);
                self::fail('A different JSON shape reused an idempotency key.');
            } catch (\InvalidArgumentException|LogicException) {
                self::assertSame(1, DB::table('ai_calls')->count());
            }
        });
        Http::assertNothingSent();
    }

    public function test_fixture_masking_idempotency_and_cost_attribution(): void
    {
        $request = new AiRequest([['role' => 'user', 'content' => 'Позвоните +7 (999) 111-22-33 или a@example.ru']], 'google/gemini-2.5-flash', 0.5);
        $this->fixture(['messages' => [['role' => 'user', 'content' => 'Позвоните [ТЕЛЕФОН] или [EMAIL]']], 'model' => 'google/gemini-2.5-flash', 'temperature' => 0.5], 7);
        $context = $this->context('same-key');
        $this->within(function () use ($request, $context): void {
            $first = app(AiGateway::class)->complete($request, $context);
            $second = app(AiGateway::class)->complete($request, $context);
            self::assertSame($first->aiCallId, $second->aiCallId);
            self::assertSame('Ответ', $first->content);
            self::assertSame(7, $first->costMicros);
            self::assertSame('call_1', $first->toolCalls[0]['id']);
            self::assertSame(['test' => 1], $first->toolCalls[0]['arguments']);
            self::assertSame(1, DB::table('ai_calls')->count());
            self::assertSame(7, (int) DB::table('ai_budgets')->value('spent_micros'));
            self::assertSame(7, (int) DB::table('object_costs')->value('cost_micros'));
            self::assertSame(0, (int) DB::table('ai_budgets')->value('reserved_micros'));
            $this->expectException(LogicException::class);
            app(AiGateway::class)->complete(new AiRequest([['role' => 'user', 'content' => 'different']], $request->model, 0.5), $context);
        });
    }

    public function test_outer_transaction_is_rejected_before_reservation_or_transport(): void
    {
        config(['runtime.ai_driver' => 'openrouter', 'features.E0-05.defaults.live_enabled' => true, 'features.E0-05.defaults.openrouter_key' => 'test-only']);
        Http::fake();
        $this->within(function (): void {
            DB::beginTransaction();
            try {
                app(AiGateway::class)->complete($this->request(), $this->context('nested'));
                self::fail('Nested transaction accepted');
            } catch (LogicException $error) {
                self::assertStringContainsString('inside a database transaction', $error->getMessage());
            } finally {
                DB::rollBack();
            }
            self::assertSame(0, DB::table('ai_calls')->count());
            Http::assertNothingSent();
        });
    }

    public function test_reconciliation_cannot_settle_an_active_http_call(): void
    {
        $this->budget(1000);
        config(['runtime.ai_driver' => 'openrouter', 'features.E0-05.defaults.live_enabled' => true, 'features.E0-05.defaults.openrouter_key' => 'test-only', 'features.E0-05.defaults.reservation_micros' => ['google/gemini-2.5-flash' => 100], 'features.E0-05.defaults.reservation_request_limits' => ['google/gemini-2.5-flash' => ['max_input_bytes' => 4096, 'max_output_tokens' => 1000]]]);
        DB::table('brands')->where('id', $this->brand)->update(['settings' => json_encode(['ai_data_policy' => ['knowledge_public' => ['any']]], JSON_THROW_ON_ERROR)]);
        Http::fake(['openrouter.ai/*' => function () {
            $id = DB::table('ai_calls')->value('id');
            $result = new AiResult('forged', [], ['promptTokens' => 1, 'completionTokens' => 1], $this->request()->model, 1, 1, $id);
            foreach ([fn () => app(Gateway::class)->reconcileUnbilled(app(TenantContext::class), $id, 'unverified'), fn () => app(Gateway::class)->reconcileSuccess(app(TenantContext::class), $result, 'unverified')] as $reconcile) {
                try {
                    $reconcile();
                    self::fail('Active reservation was reconciled');
                } catch (LogicException) {
                }
            }
            self::assertSame('reserved', DB::table('ai_calls')->value('status'));
            self::assertSame(100, (int) DB::table('ai_budgets')->value('reserved_micros'));

            return Http::response('{"model":"google/gemini-2.5-flash","choices":[{"message":{"content":"ok"}}],"usage":{"prompt_tokens":1,"completion_tokens":1,"cost":0.000004}}', 200);
        }]);
        $this->within(function (): void {
            $result = app(AiGateway::class)->complete($this->request(), $this->context('in-flight'));
            self::assertSame('ok', $result->content);
            self::assertSame(4, (int) DB::table('ai_budgets')->value('spent_micros'));
            Http::assertSentCount(1);
        });
    }

    public function test_same_idempotency_key_cannot_cross_driver_modes(): void
    {
        $this->fixture($this->request()->toArray(), 1);
        $this->within(function (): void {
            app(AiGateway::class)->complete($this->request(), $this->context('driver-switch'));
            config(['runtime.ai_driver' => 'openrouter', 'features.E0-05.defaults.live_enabled' => true, 'features.E0-05.defaults.openrouter_key' => 'test-only']);
            DB::table('brands')->where('id', $this->brand)->update(['settings' => json_encode(['ai_data_policy' => ['knowledge_public' => ['any']]], JSON_THROW_ON_ERROR)]);
            Http::fake();
            try {
                app(AiGateway::class)->complete($this->request(), $this->context('driver-switch'));
                self::fail('Cross-driver replay accepted');
            } catch (LogicException $error) {
                self::assertStringContainsString('different request or context', $error->getMessage());
            }
            Http::assertNothingSent();
        });
    }

    public function test_nested_contact_keys_and_escaped_tool_argument_keys_are_rejected(): void
    {
        $masker = app(ContactMasker::class);
        foreach ([
            ['messages' => [['role' => 'user', 'content' => [['a@example.ru' => 'value']]]]],
            ['messages' => [['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'call', 'function' => ['name' => 'lookup', 'arguments' => '{"a\\u0040example.ru":"value"}']]]]]],
        ] as $payload) {
            try {
                $masker->request($payload);
                self::fail('Contact key accepted');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('keys', $error->getMessage());
            }
        }
    }

    public function test_arbitrary_message_keys_malformed_tool_calls_and_escaped_contacts_fail_closed(): void
    {
        $masker = app(ContactMasker::class);
        $payloads = [
            ['messages' => [['role' => 'user', 'content' => 'ok', 'a@example.test' => 'x']]],
            ['messages' => [['role' => 'assistant', 'content' => 'ok', 'tool_calls' => 'a@example.test']]],
            ['messages' => [['role' => 'user', 'content' => 'Документ: {"email":"a\\u0040example.test"}']]],
            ['messages' => [['role' => 'user', 'content' => 'ok', 'extra' => '{"email":"a\\u0040example.test"}']]],
            ['messages' => [['role' => 'user', 'content' => 'a\\\\u0040example.test']]],
            ['messages' => [['role' => 'user', 'content' => 'a\\u005cu0040example.test']]],
            ['messages' => [['role' => 'user', 'content' => 'ok', 'a\\u0040example.test' => 'x']]],
            ['messages' => [['role' => 'user', 'content' => (object) ['email' => 'a@example.test']]]],
        ];
        foreach ($payloads as $payload) {
            try {
                $masker->request($payload);
                self::fail('Unsafe contact payload accepted');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_unsafe_message_fields_never_reach_live_http(): void
    {
        config(['runtime.ai_driver' => 'openrouter', 'features.E0-05.defaults.live_enabled' => true, 'features.E0-05.defaults.openrouter_key' => 'test-only']);
        DB::table('brands')->where('id', $this->brand)->update(['settings' => json_encode(['ai_data_policy' => ['knowledge_public' => ['any']]], JSON_THROW_ON_ERROR)]);
        Http::fake();
        $request = new AiRequest([['role' => 'user', 'content' => 'Контакт a\\u0040example.test']], 'google/gemini-2.5-flash', 0.5);
        $this->within(function () use ($request): void {
            try {
                app(AiGateway::class)->complete($request, $this->context('encoded-contact'));
                self::fail('Unsafe message reached transport');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('AI data could not be safely prepared.', $error->getMessage());
            }
            Http::assertNothingSent();
            self::assertSame(0, DB::table('ai_calls')->count());
        });
    }

    public function test_anthropic_limit_is_applied_to_the_actual_serialized_http_body(): void
    {
        $this->budget(1000);
        $model = 'anthropic/claude-sonnet-5.5';
        $request = new AiRequest([['role' => 'user', 'content' => 'проверка']], $model, 0.5, ['type' => 'json_schema', 'json_schema' => ['name' => 'result', 'schema' => ['type' => 'object', 'description' => str_repeat('слово', 300)]]]);
        $wire = app(OpenRouterDriver::class)->wireBody(app(ContactMasker::class)->request($request->toArray()));
        $bytes = strlen(json_encode($wire, JSON_THROW_ON_ERROR));
        config(['runtime.ai_driver' => 'openrouter', 'features.E0-05.defaults.live_enabled' => true, 'features.E0-05.defaults.openrouter_key' => 'test-only', 'features.E0-05.defaults.reservation_micros' => [$model => 100], 'features.E0-05.defaults.reservation_request_limits' => [$model => ['max_input_bytes' => $bytes - 1, 'max_output_tokens' => 700]]]);
        DB::table('brands')->where('id', $this->brand)->update(['settings' => json_encode(['ai_data_policy' => ['knowledge_public' => ['any']]], JSON_THROW_ON_ERROR)]);
        Http::fake(['openrouter.ai/*' => Http::response('{"model":"anthropic/claude-sonnet-5.5","choices":[{"message":{"content":"ok"}}],"usage":{"prompt_tokens":1,"completion_tokens":1,"cost":0.000004}}', 200)]);
        $this->within(function () use ($request, $model, $bytes): void {
            try {
                app(AiGateway::class)->complete($request, $this->context('too-big'));
                self::fail('Oversized wire payload sent');
            } catch (LogicException $error) {
                self::assertStringContainsString('exceeds the configured reservation bounds', $error->getMessage());
            }
            Http::assertNothingSent();
            config(['features.E0-05.defaults.reservation_request_limits' => [$model => ['max_input_bytes' => $bytes, 'max_output_tokens' => 700]]]);
            self::assertSame('ok', app(AiGateway::class)->complete($request, $this->context('at-limit'))->content);
            Http::assertSent(fn ($sent) => strlen($sent->body()) === $bytes);
        });
    }

    public function test_a_failed_parent_accepts_only_one_child_even_with_different_keys(): void
    {
        $this->within(function (): void {
            try {
                app(AiGateway::class)->complete($this->request(), $this->context('parent'));
                self::fail('Missing fixture accepted');
            } catch (Rejected) {
            }
            $parent = DB::table('ai_calls')->value('id');
            $this->fixture($this->request()->toArray(), 1);
            $retry = fn (string $key) => new AiContext($this->organization, $this->brand, 'responder', 'seller', 'brand', $this->brand, $key, ['knowledge_public'], 2, $parent);
            app(AiGateway::class)->complete($this->request(), $retry('child-one'));
            try {
                app(AiGateway::class)->complete($this->request(), $retry('child-two'));
                self::fail('Duplicate retry accepted');
            } catch (LogicException $error) {
                self::assertStringContainsString('already has a retry', $error->getMessage());
            }
            self::assertSame(1, DB::table('ai_calls')->where('parent_call_id', $parent)->count());
        });
    }

    public function test_replay_in_a_new_month_does_not_create_an_extra_budget(): void
    {
        $this->fixture($this->request()->toArray(), 1);
        $this->within(function (): void {
            $first = app(AiGateway::class)->complete($this->request(), $this->context('month-crossing'));
            $this->travel(1)->months();
            $replay = app(AiGateway::class)->complete($this->request(), $this->context('month-crossing'));
            self::assertSame($first->aiCallId, $replay->aiCallId);
            self::assertSame(1, DB::table('ai_calls')->count());
            self::assertSame(1, DB::table('ai_budgets')->count());
        });
    }

    public function test_empty_provider_response_is_an_error_but_known_charge_is_accounted(): void
    {
        $this->budget(1000);
        config(['features.E0-05.defaults.reservation_micros' => ['google/gemini-2.5-flash' => 100]]);
        $result = ['content' => null, 'toolCalls' => [], 'usage' => ['promptTokens' => 5, 'completionTokens' => 3], 'model' => $this->request()->model, 'latencyMs' => 1, 'costMicros' => 1];
        file_put_contents($this->fixtures.'/'.CanonicalJson::hash($this->request()->toArray()).'.json', json_encode(['result' => $result], JSON_THROW_ON_ERROR));
        $this->within(function (): void {
            $answer = app(AiGateway::class)->complete($this->request(), $this->context('empty'));
            self::assertFalse($answer->ok());
            self::assertSame('empty_response', $answer->error['code']);
            self::assertSame('completed', DB::table('ai_calls')->value('status'));
            self::assertSame(1, (int) DB::table('ai_budgets')->value('spent_micros'));
            self::assertSame(0, (int) DB::table('ai_budgets')->value('reserved_micros'));
        });
    }

    public function test_masking_covers_schema_descriptions_and_rejects_contact_identifiers(): void
    {
        $masker = app(ContactMasker::class);
        $payload = $masker->request([
            'messages' => [['role' => 'user', 'content' => 'a@example.ru']],
            'tools' => [['type' => 'function', 'function' => ['name' => 'lookup', 'description' => 'Связаться: b@example.ru']]],
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'result', 'schema' => ['description' => 'Звонить +7 (999) 111-22-33']]],
        ]);
        self::assertSame('[EMAIL]', $payload['messages'][0]['content']);
        self::assertSame('Связаться: [EMAIL]', $payload['tools'][0]['function']['description']);
        self::assertSame('Звонить [ТЕЛЕФОН]', $payload['response_format']['json_schema']['schema']['description']);
        $this->expectExceptionMessage('identifiers');
        $masker->request(['messages' => [['role' => 'user', 'content' => 'ok']], 'tools' => [['function' => ['name' => 'a@example.ru']]]]);
    }

    public function test_same_key_with_different_masked_contacts_conflicts_without_storing_raw_contacts(): void
    {
        $this->fixture(['messages' => [['role' => 'user', 'content' => '[EMAIL]']], 'model' => 'google/gemini-2.5-flash', 'temperature' => 0.5], 1);
        $this->within(function (): void {
            $context = $this->context('contact-change');
            app(AiGateway::class)->complete(new AiRequest([['role' => 'user', 'content' => 'a@example.ru']], 'google/gemini-2.5-flash', 0.5), $context);
            $row = json_encode((array) DB::table('ai_calls')->first(), JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('a@example.ru', $row);
            $this->expectExceptionMessage('different request or context');
            app(AiGateway::class)->complete(new AiRequest([['role' => 'user', 'content' => 'b@example.ru']], 'google/gemini-2.5-flash', 0.5), $context);
        });
    }

    public function test_exhausted_or_unbounded_budget_blocks_before_fixture_or_provider(): void
    {
        $this->budget(99);
        config(['features.E0-05.defaults.reservation_micros' => ['google/gemini-2.5-flash' => 100]]);
        $this->within(function (): void {
            try {
                app(AiGateway::class)->complete($this->request(), $this->context('blocked'));
                self::fail('Budget not enforced');
            } catch (LogicException $e) {
                self::assertStringContainsString('budget exhausted', $e->getMessage());
            }
            self::assertSame(0, DB::table('ai_calls')->count());
            config(['features.E0-05.defaults.reservation_micros' => []]);
            $this->expectExceptionMessage('requires a model reservation bound');
            app(AiGateway::class)->complete($this->request(), $this->context('no-bound'));
        });
    }

    public function test_configured_unit_budget_cannot_be_ignored_without_conversion(): void
    {
        $this->budget(1000);
        DB::table('ai_budgets')->update(['limit_units' => '10.0000']);
        $this->within(function (): void {
            $this->expectExceptionMessage('requires an approved conversion');
            app(AiGateway::class)->complete($this->request(), $this->context('units-not-configured'));
        });
    }

    public function test_actual_cost_above_reservation_is_accounted_and_blocks_further_calls(): void
    {
        $this->budget(1000);
        config(['features.E0-05.defaults.reservation_micros' => ['google/gemini-2.5-flash' => 100]]);
        $this->fixture($this->request()->toArray(), 150);
        $this->within(function (): void {
            $result = app(AiGateway::class)->complete($this->request(), $this->context('overspend'));
            self::assertSame(150, $result->costMicros);
            self::assertSame(150, (int) DB::table('ai_budgets')->value('spent_micros'));
            self::assertTrue((bool) DB::table('ai_budgets')->value('reservation_breached'));
            self::assertTrue((bool) DB::table('ai_calls')->value('reserve_exceeded'));
            $this->expectExceptionMessage('operator review');
            app(AiGateway::class)->complete($this->request(), $this->context('next-call'));
        });
    }

    public function test_missing_fake_fixture_releases_reservation_and_throws(): void
    {
        $this->budget(1000);
        config(['features.E0-05.defaults.reservation_micros' => ['google/gemini-2.5-flash' => 100]]);
        $this->within(function (): void {
            try {
                app(AiGateway::class)->complete($this->request(), $this->context('missing'));
                self::fail('Fixture missing must fail');
            } catch (Rejected $e) {
                self::assertStringContainsString('fixture missing', $e->getMessage());
            }
            self::assertSame('failed', DB::table('ai_calls')->value('status'));
            self::assertSame(0, (int) DB::table('ai_budgets')->value('reserved_micros'));
            self::assertSame(0, (int) DB::table('ai_budgets')->value('unresolved_count'));
        });
    }

    public function test_uncertain_network_outcome_keeps_reservation_and_does_not_retry(): void
    {
        $this->budget(1000);
        config(['runtime.ai_driver' => 'openrouter', 'features.E0-05.defaults.live_enabled' => true, 'features.E0-05.defaults.openrouter_key' => 'test-only', 'features.E0-05.defaults.reservation_micros' => ['google/gemini-2.5-flash' => 100], 'features.E0-05.defaults.reservation_request_limits' => ['google/gemini-2.5-flash' => ['max_input_bytes' => 4096, 'max_output_tokens' => 1000]]]);
        DB::table('brands')->where('id', $this->brand)->update(['settings' => json_encode(['ai_data_policy' => ['knowledge_public' => ['any']]], JSON_THROW_ON_ERROR)]);
        $attempts = 0;
        Http::fake(['openrouter.ai/*' => function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('test-only timeout');
        }]);
        $this->within(function () use (&$attempts): void {
            $first = app(AiGateway::class)->complete($this->request(), $this->context('uncertain'));
            $second = app(AiGateway::class)->complete($this->request(), $this->context('uncertain'));
            self::assertSame('provider_uncertain', $first->error['code']);
            self::assertSame($first->aiCallId, $second->aiCallId);
            self::assertSame('uncertain', DB::table('ai_calls')->value('status'));
            self::assertSame(100, (int) DB::table('ai_budgets')->value('reserved_micros'));
            self::assertSame(1, (int) DB::table('ai_budgets')->value('unresolved_count'));
            self::assertSame(1, $attempts);
        });
    }

    public function test_uncertain_result_cannot_be_reconciled_with_unverified_success(): void
    {
        $this->budget(1000);
        config(['features.E0-05.defaults.reservation_micros' => ['google/gemini-2.5-flash' => 100]]);
        $this->fixture($this->request()->toArray(), null);
        $this->within(function (): void {
            $context = $this->context('unknown-cost');
            $first = app(AiGateway::class)->complete($this->request(), $context);
            self::assertSame('cost_unknown', $first->error['code']);
            $stored = DB::table('ai_calls')->value('result_encrypted');
            self::assertStringNotContainsString('Ответ', $stored);
            $normalized = json_decode(Crypt::decryptString($stored), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('Ответ', $normalized['content']);
            self::assertNull($normalized['costMicros']);
            self::assertSame(100, (int) DB::table('ai_budgets')->value('reserved_micros'));
            $confirmed = new AiResult('confirmed', [], ['promptTokens' => 5, 'completionTokens' => 3], $this->request()->model, 1, 55, $first->aiCallId);
            try {
                app(Gateway::class)->reconcileSuccess(app(TenantContext::class), $confirmed, 'provider-receipt-123');
                self::fail('Unverified receipt accepted');
            } catch (LogicException $error) {
                self::assertStringContainsString('verified provider evidence', $error->getMessage());
            }
            self::assertSame(0, (int) DB::table('ai_budgets')->value('spent_micros'));
            self::assertSame(100, (int) DB::table('ai_budgets')->value('reserved_micros'));
            self::assertSame('cost_unknown', app(AiGateway::class)->complete($this->request(), $context)->error['code']);
        });
    }

    public function test_new_budget_cannot_ignore_a_previous_unknown_unlimited_call(): void
    {
        $this->fixture($this->request()->toArray(), null);
        $this->within(function (): void {
            $unknown = app(AiGateway::class)->complete($this->request(), $this->context('unknown-unlimited'));
            self::assertSame('cost_unknown', $unknown->error['code']);
            DB::table('ai_budgets')->update(['limit_micros' => 1000]);
            config(['features.E0-05.defaults.reservation_micros' => ['google/gemini-2.5-flash' => 100]]);
            try {
                app(AiGateway::class)->complete($this->request(), $this->context('after-limit'));
                self::fail('Unknown charge ignored');
            } catch (LogicException $error) {
                self::assertStringContainsString('requires reconciliation', $error->getMessage());
            }
            self::assertSame(1, DB::table('ai_calls')->count());
        });
    }

    public function test_confirmed_unbilled_reconciliation_requires_evidence_and_correct_tenant(): void
    {
        $this->budget(1000);
        config(['features.E0-05.defaults.reservation_micros' => ['google/gemini-2.5-flash' => 100]]);
        $this->fixture($this->request()->toArray(), null);
        $this->within(function (): void {
            $first = app(AiGateway::class)->complete($this->request(), $this->context('unbilled'));
            try {
                app(Gateway::class)->reconcileUnbilled(app(TenantContext::class), $first->aiCallId, '');
                self::fail('Evidence missing');
            } catch (\InvalidArgumentException) {
            }
            try {
                app(Gateway::class)->reconcileUnbilled(new TenantContext((string) Str::ulid(), $this->brand), $first->aiCallId, 'receipt');
                self::fail('Cross-tenant accepted');
            } catch (\InvalidArgumentException) {
            }
            self::assertSame(100, (int) DB::table('ai_budgets')->value('reserved_micros'));
            try {
                app(Gateway::class)->reconcileUnbilled(app(TenantContext::class), $first->aiCallId, 'confirmed-no-charge');
                self::fail('Unverified evidence released reservation');
            } catch (LogicException $error) {
                self::assertStringContainsString('verified provider evidence', $error->getMessage());
            }
            self::assertSame('uncertain', DB::table('ai_calls')->value('status'));
            self::assertSame(100, (int) DB::table('ai_budgets')->value('reserved_micros'));
        });
    }

    public function test_live_transport_is_opt_in_and_requires_brand_data_grant(): void
    {
        config(['runtime.ai_driver' => 'openrouter', 'features.E0-05.defaults.openrouter_key' => 'test-only']);
        Http::fake();
        $this->within(function (): void {
            try {
                app(AiGateway::class)->complete($this->request(), $this->context('no-live'));
                self::fail('Live disabled');
            } catch (LogicException $e) {
                self::assertStringContainsString('disabled', $e->getMessage());
            }
            config(['features.E0-05.defaults.live_enabled' => true]);
            try {
                app(AiGateway::class)->complete($this->request(), $this->context('no-grant'));
                self::fail('Missing data grant');
            } catch (LogicException $e) {
                self::assertStringContainsString('data policy', $e->getMessage());
            }
            self::assertSame(0, DB::table('ai_calls')->count());
            Http::assertNothingSent();
        });
    }

    public function test_live_budget_rejects_unbounded_or_oversized_requests_before_transport(): void
    {
        $this->budget(1000);
        config(['runtime.ai_driver' => 'openrouter', 'features.E0-05.defaults.live_enabled' => true, 'features.E0-05.defaults.openrouter_key' => 'test-only', 'features.E0-05.defaults.reservation_micros' => ['google/gemini-2.5-flash' => 100]]);
        DB::table('brands')->where('id', $this->brand)->update(['settings' => json_encode(['ai_data_policy' => ['knowledge_public' => ['any']]], JSON_THROW_ON_ERROR)]);
        Http::fake();
        $this->within(function (): void {
            try {
                app(AiGateway::class)->complete($this->request(), $this->context('unbounded'));
                self::fail('Unbounded live budget accepted');
            } catch (LogicException $e) {
                self::assertStringContainsString('bounded request sizes', $e->getMessage());
            }
            config(['features.E0-05.defaults.reservation_request_limits' => ['google/gemini-2.5-flash' => ['max_input_bytes' => 60, 'max_output_tokens' => 700]]]);
            try {
                app(AiGateway::class)->complete($this->request(), $this->context('oversized'));
                self::fail('Oversized live request accepted');
            } catch (LogicException $e) {
                self::assertStringContainsString('exceeds the configured reservation bounds', $e->getMessage());
            }
            self::assertSame(0, DB::table('ai_calls')->count());
            Http::assertNothingSent();
        });
    }

    public function test_record_driver_and_fake_share_masked_normalized_shape(): void
    {
        config(['runtime.ai_driver' => 'record', 'features.E0-05.defaults.live_enabled' => true, 'features.E0-05.defaults.openrouter_key' => 'test-only']);
        DB::table('brands')->where('id', $this->brand)->update(['settings' => json_encode(['ai_data_policy' => ['knowledge_public' => ['any']]], JSON_THROW_ON_ERROR)]);
        Http::fake(['openrouter.ai/*' => Http::response('{"model":"google/gemini-2.5-flash","choices":[{"message":{"content":null,"tool_calls":[{"id":"call_1","function":{"name":"test","arguments":"{\\"test\\":1}"}}]}}],"usage":{"prompt_tokens":5,"completion_tokens":3,"cost":0.000000100000000001}}', 200, ['Content-Type' => 'application/json'])]);
        $this->within(function (): void {
            $request = new AiRequest([['role' => 'user', 'content' => 'Почта a@example.ru']], 'google/gemini-2.5-flash', 0.5);
            $recorded = app(AiGateway::class)->complete($request, $this->context('record'));
            self::assertNull($recorded->error);
            self::assertSame(1, $recorded->costMicros);
            self::assertSame('call_1', $recorded->toolCalls[0]['id']);
            Http::assertSent(fn ($sent) => $sent['messages'][0]['content'] === 'Почта [EMAIL]');
            config(['runtime.ai_driver' => 'fake', 'features.E0-05.defaults.fixture_directory' => storage_path('app/private/ai-recordings')]);
            $replayed = app(AiGateway::class)->complete($request, $this->context('fake-copy'));
            self::assertSame($recorded->toolCalls, $replayed->toolCalls);
            self::assertSame($recorded->costMicros, $replayed->costMicros);
            Http::assertSentCount(1);
        });
    }

    public function test_threshold_configuration_requires_transactional_outbox_before_call(): void
    {
        config(['features.E0-05.defaults.budget_threshold_basis_points' => 8000]);
        $contract = NotificationDispatcher::class;
        if (interface_exists($contract) && app()->bound($contract)) {
            $this->budget(100);
            config(['features.E0-05.defaults.reservation_micros' => ['google/gemini-2.5-flash' => 100]]);
            $this->fixture($this->request()->toArray(), 80);
            $this->within(function (): void {
                $result = app(AiGateway::class)->complete($this->request(), $this->context('threshold'));
                self::assertSame(80, $result->costMicros);
                $event = json_decode(DB::table('outbox_events')->value('envelope'), true, 512, JSON_THROW_ON_ERROR);
                self::assertSame('ai.budget.threshold_reached', $event['type']);
                self::assertSame('0.8000', $event['data']['fraction']);
                self::assertNotNull(DB::table('ai_budgets')->value('threshold_notified_at'));
                app(AiGateway::class)->complete($this->request(), $this->context('threshold'));
                self::assertSame(1, DB::table('outbox_events')->count());
            });
        } else {
            $this->within(function (): void {
                $this->expectExceptionMessage('transactional outbox');
                app(AiGateway::class)->complete($this->request(), $this->context('threshold'));
            });
        }
    }

    public function test_planned_model_and_foreign_context_fail_closed(): void
    {
        $this->within(function (): void {
            try {
                app(AiGateway::class)->complete(new AiRequest([['role' => 'user', 'content' => 'x']], 'gigachat', 0.0), $this->context('planned'));
                self::fail('Planned model accepted');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('not active', $e->getMessage());
            }
            $wrong = new AiContext((string) Str::ulid(), $this->brand, 'responder', 'seller', 'brand', $this->brand, 'foreign', ['knowledge_public']);
            $this->expectExceptionMessage('does not match the active tenant');
            app(AiGateway::class)->complete($this->request(), $wrong);
        });
    }

    public function test_rate_limit_is_not_retried_or_treated_as_unbilled(): void
    {
        config(['runtime.ai_driver' => 'openrouter', 'features.E0-05.defaults.live_enabled' => true, 'features.E0-05.defaults.openrouter_key' => 'test-only']);
        DB::table('brands')->where('id', $this->brand)->update(['settings' => json_encode(['ai_data_policy' => ['knowledge_public' => ['any']]], JSON_THROW_ON_ERROR)]);
        Http::fake(['openrouter.ai/*' => Http::sequence()->push('rate-limited', 429)->push('rate-limited', 429)->push('{"model":"google/gemini-2.5-flash","choices":[{"message":{"content":"ok"}}],"usage":{"prompt_tokens":5,"completion_tokens":3,"cost":0.000004}}', 200)]);
        $this->within(function (): void {
            $result = app(AiGateway::class)->complete($this->request(), $this->context('rate-limit'));
            self::assertSame('provider_uncertain', $result->error['code']);
            self::assertNull($result->costMicros);
            self::assertSame('uncertain', DB::table('ai_calls')->value('status'));
            self::assertSame(1, (int) DB::table('ai_budgets')->value('unresolved_count'));
            self::assertSame(0, (int) DB::table('ai_budgets')->value('spent_micros'));
            self::assertSame(1, DB::table('ai_calls')->count());
            Http::assertSentCount(1);
        });
    }

    public function test_prototype_json_parsing_is_preserved(): void
    {
        $result = new AiResult("```json\n{\"ok\":true}\n```", [], [], 'test', 0, 0, 'test');
        self::assertSame(['ok' => true], $result->json());
        $embedded = new AiResult('prefix {"a":1} suffix', [], [], 'test', 0, 0, 'test');
        self::assertSame(['a' => 1], $embedded->json());
    }

    public function test_explicit_seeders_preserve_providers_and_exact_prompt_bytes(): void
    {
        app(ProviderSeeder::class)->run();
        app(PromptSetsSeeder::class)->run();
        app(PromptSetsSeeder::class)->run();
        self::assertSame(6, DB::table('prompt_sets')->count());
        $hashes = [
            'extractor' => '0549fa708849114440ab6002e2a97db1deffca724fee0b9bed9ab7018aafb7ce',
            'guard' => '6f3fac37b72b60b8f58a4e7fdd8d9ec00ce12f41dae6a933d7f011f682ba5048',
            'judge' => 'bb1ca69710eab47bb16ec338d3fc0b8fe547c756058c6bd2c603040d3a3db817',
            'responder' => 'f15f2a2b660e31e9bf49d3492b75a89757005e16053d42eaf70c508239da4e09',
            'simulator' => '0ac4c14f0d10e73f5449ee8f7d718cb454d9135bf4d522e7c1bde27191e24b63',
            'summary' => 'fc24174d3e081db79d90168a5c92e4e0b54b904b53a93540c8c011a8dd91f2b8',
        ];
        foreach ($hashes as $name => $hash) {
            $row = DB::table('prompt_sets')->where('name', $name)->first();
            self::assertSame($hash, $row->checksum);
            self::assertSame($hash, hash('sha256', $row->body));
        }
        self::assertSame('planned', DB::table('ai_providers')->where('code', 'gigachat')->value('status'));
        DB::table('ai_providers')->where('code', 'gemini')->update(['status' => 'planned']);
        app(ProviderSeeder::class)->run();
        self::assertSame('planned', DB::table('ai_providers')->where('code', 'gemini')->value('status'));
        $this->within(function (): void {
            $this->expectException(\InvalidArgumentException::class);
            app(AiGateway::class)->complete($this->request(), $this->context('disabled-provider'));
        });
    }

    public function test_exact_decimal_cost_rounds_up_without_float(): void
    {
        self::assertSame(1, Money::micros('0.00000000000001'));
        self::assertSame(123457, Money::micros('0.12345601'));
        self::assertSame(12, Money::micros('1.2e-5'));
        self::assertSame('0.000000100000000001', Money::decode('{"usage":{"cost":0.000000100000000001}}')['usage']['cost']);
    }

    private function within(\Closure $callback): mixed
    {
        return app(TenantContextRunner::class)->run(new TenantContext($this->organization, $this->brand, source: 'job'), $callback);
    }

    private function context(string $key): AiContext
    {
        return new AiContext($this->organization, $this->brand, 'responder', 'seller', 'brand', $this->brand, $key, ['knowledge_public']);
    }

    private function request(): AiRequest
    {
        return new AiRequest([['role' => 'user', 'content' => 'Привет']], 'google/gemini-2.5-flash', 0.5);
    }

    private function fixture(array $body, ?int $cost): void
    {
        $result = ['content' => 'Ответ', 'toolCalls' => [['id' => 'call_1', 'name' => 'test', 'arguments' => ['test' => 1]]], 'usage' => ['promptTokens' => 5, 'completionTokens' => 3], 'model' => $body['model'], 'latencyMs' => 1, 'costMicros' => $cost];
        file_put_contents($this->fixtures.'/'.CanonicalJson::hash($body).'.json', json_encode(['result' => $result], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function budget(int $limit): void
    {
        DB::table('ai_budgets')->insert(['id' => (string) Str::ulid(), 'organization_id' => $this->organization, 'brand_id' => $this->brand, 'family' => 'seller', 'period' => now('UTC')->startOfMonth()->toDateString(), 'limit_micros' => $limit, 'spent_micros' => 0, 'reserved_micros' => 0, 'unresolved_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }
}
