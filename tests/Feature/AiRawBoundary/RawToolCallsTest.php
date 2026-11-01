<?php

namespace Tests\Feature\AiRawBoundary;

use App\Ai\CanonicalJson;
use App\Ai\Drivers\FakeDriver;
use App\Ai\Drivers\OpenRouterDriver;
use App\Ai\Drivers\Rejected;
use App\Ai\Drivers\Uncertain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RawToolCallsTest extends TestCase
{
    private string $directory;

    private array $body = ['model' => 'synthetic-model', 'messages' => [['role' => 'user', 'content' => 'synthetic']]];

    protected function setUp(): void
    {
        parent::setUp();
        $db = DB::selectOne('select current_database() as name, host(inet_server_addr()) as host, rolsuper, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls from pg_roles where rolname = current_user');
        if (! app()->environment('testing') || config('database.default') !== 'pgsql'
            || ! is_string(env('DB_TEST_DATABASE')) || ! str_contains(env('DB_TEST_DATABASE'), '_test_')
            || DB::connection()->getDatabaseName() !== env('DB_TEST_DATABASE') || $db->name !== env('DB_TEST_DATABASE')
            || env('APP_PREVIEW_DATABASE') === $db->name || (! str_starts_with((string) $db->host, '127.') && $db->host !== '::1')
            || $db->rolsuper || $db->rolcreatedb || $db->rolcreaterole || $db->rolreplication || $db->rolbypassrls) {
            throw new \RuntimeException('An isolated PostgreSQL test database is required.');
        }
        Http::preventStrayRequests();
        $this->directory = base_path('.local/tmp/c03-fixture-'.Str::random(20));
        mkdir($this->directory, 0700, true);
        config(['features.E0-05.defaults.fixture_directory' => $this->directory,
            'features.E0-05.defaults.openrouter_key' => 'synthetic-private-key']);
    }

    protected function tearDown(): void
    {
        $file = $this->directory.DIRECTORY_SEPARATOR.CanonicalJson::hash($this->body).'.json';
        if (is_file($file)) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function test_fake_fixture_returns_the_actual_raw_argument_string(): void
    {
        $raw = ' { "city": "Москва", "empty": {}, "items": [] } ';
        $this->writeFixture($raw);
        $value = app(FakeDriver::class)->completeWithRawToolCalls($this->body);
        self::assertSame($raw, $value['raw_tool_calls'][0]['arguments_json']);
        self::assertSame(['id' => 'call-1', 'name' => 'create_promise', 'arguments' => ['city' => 'Москва', 'empty' => [], 'items' => []]], $value['result']['toolCalls'][0]);
        Http::assertNothingSent();
    }

    public function test_mocked_openrouter_returns_original_raw_string_from_the_same_response(): void
    {
        $raw = " {\n  \"city\": \"Москва\", \"empty\": {}\n} ";
        Http::fake(['openrouter.ai/*' => Http::response($this->providerBody($raw), 200, ['Content-Type' => 'application/json'])]);
        $value = app(OpenRouterDriver::class)->completeWithRawToolCalls($this->body);
        self::assertSame($raw, $value['raw_tool_calls'][0]['arguments_json']);
        self::assertSame(['city' => 'Москва', 'empty' => []], $value['result']['toolCalls'][0]['arguments']);
        self::assertSame(['promptTokens' => 5, 'completionTokens' => 3], $value['result']['usage']);
        self::assertSame(2000, $value['result']['costMicros']);
        Http::assertSentCount(1);
    }

    public static function invalidArguments(): array
    {
        return [
            'array root' => ['[]'], 'null root' => ['null'], 'malformed' => ['{"secret":"synthetic-private-value"'],
            'duplicate key' => ['{"secret":"synthetic-private-value","secret":2}'],
            'escaped duplicate' => ['{"name":1,"\\u006eame":2}'],
            'nested duplicate' => ['{"nested":{"x":1,"x":2}}'],
        ];
    }

    #[DataProvider('invalidArguments')]
    public function test_fake_rejects_ambiguous_or_non_object_raw_arguments_without_leaking(#[\SensitiveParameter] string $raw): void
    {
        $this->writeFixture($raw);
        try {
            app(FakeDriver::class)->completeWithRawToolCalls($this->body);
            self::fail('Ambiguous arguments were accepted.');
        } catch (Rejected $error) {
            self::assertSame('Protected AI fixture unavailable.', $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('synthetic-private-value', (string) $error);
        }
        Http::assertNothingSent();
    }

    #[DataProvider('invalidArguments')]
    public function test_mocked_openrouter_rejects_ambiguous_or_non_object_raw_arguments_without_leaking(#[\SensitiveParameter] string $raw): void
    {
        Http::fake(['openrouter.ai/*' => Http::response($this->providerBody($raw), 200)]);
        try {
            app(OpenRouterDriver::class)->completeWithRawToolCalls($this->body);
            self::fail('Ambiguous arguments were accepted.');
        } catch (Uncertain $error) {
            self::assertSame('Provider responded but usage or result cannot be confirmed.', $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('synthetic-private-value', (string) $error);
            self::assertStringNotContainsString('synthetic-private-key', (string) $error);
        }
        Http::assertSentCount(1);
    }

    public function test_empty_object_is_preserved_and_legacy_complete_remains_unchanged(): void
    {
        $this->writeFixture('{}');
        $protected = app(FakeDriver::class)->completeWithRawToolCalls($this->body);
        self::assertSame('{}', $protected['raw_tool_calls'][0]['arguments_json']);
        $this->writeFixture(['city' => 'Москва']);
        $legacy = app(FakeDriver::class)->complete($this->body);
        self::assertSame(['city' => 'Москва'], $legacy['toolCalls'][0]['arguments']);
        try {
            app(FakeDriver::class)->completeWithRawToolCalls($this->body);
            self::fail('A historical decoded fixture was treated as raw evidence.');
        } catch (Rejected) {
            self::assertTrue(true);
        }
        Http::fake(['openrouter.ai/*' => Http::response($this->providerBody('[]'), 200)]);
        self::assertSame([], app(OpenRouterDriver::class)->complete($this->body)['toolCalls'][0]['arguments']);
        Http::assertSentCount(1);
    }

    public function test_duplicate_outer_fields_and_provider_errors_fail_closed(): void
    {
        $body = '{"choices":[{"finish_reason":"tool_calls","message":{"tool_calls":[{"id":"one","function":{"name":"create_promise","arguments":"{}","arguments":"{}"}}]}}],"usage":{"prompt_tokens":1,"completion_tokens":1}}';
        Http::fake(['openrouter.ai/*' => Http::response($body, 200)]);
        try {
            app(OpenRouterDriver::class)->completeWithRawToolCalls($this->body);
            self::fail('Duplicate provider field was accepted.');
        } catch (Uncertain $error) {
            self::assertNull($error->getPrevious());
        }
        Http::assertSentCount(1);
    }

    public function test_provider_transport_and_status_errors_do_not_retry_or_leak(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response('synthetic-private-response', 429)]);
        try {
            app(OpenRouterDriver::class)->completeWithRawToolCalls($this->body);
            self::fail('A provider error was accepted.');
        } catch (Uncertain $error) {
            self::assertSame('Provider outcome is unknown.', $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('synthetic-private-response', (string) $error);
            self::assertStringNotContainsString('synthetic-private-key', (string) $error);
        }
        Http::assertSentCount(1);
    }

    public function test_fake_rejects_duplicate_ids_and_duplicate_outer_keys(): void
    {
        $file = $this->directory.DIRECTORY_SEPARATOR.CanonicalJson::hash($this->body).'.json';
        $call = ['id' => 'same', 'name' => 'create_promise', 'arguments' => '{}'];
        file_put_contents($file, json_encode(['result' => ['toolCalls' => [$call, $call]]], JSON_THROW_ON_ERROR));
        try {
            app(FakeDriver::class)->completeWithRawToolCalls($this->body);
            self::fail('A duplicate call id was accepted.');
        } catch (Rejected $error) {
            self::assertNull($error->getPrevious());
        }
        file_put_contents($file, '{"result":{"toolCalls":[],"toolCalls":[]}}');
        try {
            app(FakeDriver::class)->completeWithRawToolCalls($this->body);
            self::fail('Duplicate fixture fields were accepted.');
        } catch (Rejected $error) {
            self::assertNull($error->getPrevious());
        }
        Http::assertNothingSent();
    }

    public function test_missing_provider_configuration_is_rejected_before_http(): void
    {
        config(['features.E0-05.defaults.openrouter_key' => null]);
        try {
            app(OpenRouterDriver::class)->completeWithRawToolCalls($this->body);
            self::fail('An unconfigured provider was used.');
        } catch (Rejected $error) {
            self::assertSame('AI provider is not configured.', $error->getMessage());
        }
        Http::assertNothingSent();
    }

    private function writeFixture(mixed $arguments): void
    {
        $result = ['content' => null, 'toolCalls' => [['id' => 'call-1', 'name' => 'create_promise', 'arguments' => $arguments]],
            'usage' => ['promptTokens' => 5, 'completionTokens' => 3], 'model' => 'synthetic-model', 'latencyMs' => 1, 'costMicros' => 2000];
        file_put_contents($this->directory.DIRECTORY_SEPARATOR.CanonicalJson::hash($this->body).'.json', json_encode(['result' => $result], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function providerBody(string $arguments): string
    {
        return json_encode(['choices' => [['finish_reason' => 'tool_calls', 'message' => ['tool_calls' => [['id' => 'call-1',
            'function' => ['name' => 'create_promise', 'arguments' => $arguments]]]]]],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3, 'cost' => 0.002], 'model' => 'synthetic-model'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
