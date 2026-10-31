<?php

use App\Privacy\Secrets\SecretBoundary;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SecretBoundaryTest extends TestCase
{
    public static function sensitivePayloads(): array
    {
        return [
            'api key field' => [['messages' => [['content' => ['api_key' => 'demo_value_not_real']]]]],
            'zero width key' => [['messages' => [['content' => ["a\u{200b}pi_key" => 'arbitrary']]]]],
            'encoded classifier' => [['messages' => [['content' => ['facts' => [['topic' => 'api&#95;key', 'value' => 'arbitrary']]]]]]],
            'short bearer' => [['messages' => [['content' => 'Bearer a']]]],
            'url encoded short bearer' => [['messages' => [['content' => 'Bearer%20z']]]],
            'bearer' => [['messages' => [['content' => 'Bearer syntheticvalue987654321']]]],
            'password text' => [['messages' => [['content' => 'password = pretend-value']]]],
            'topic marked secret' => [['messages' => [['content' => ['facts' => [['topic' => 'api_key', 'value' => 'arbitrary']]]]]]],
            'private key header' => [['messages' => [['content' => '-----BEGIN PRIVATE KEY-----']]]],
            'github format' => [['messages' => [['content' => 'ghp_'.str_repeat('q', 24)]]]],
            'url encoding' => [['messages' => [['content' => 'api%5Fkey%3Dpretendvalue']]]],
            'html encoding' => [['messages' => [['content' => 'password&#61;pretendvalue']]]],
            'nested tool json' => [['tool_calls' => ['arguments' => '{"access_token":"arbitrary"}']]],
            'split token' => [['messages' => [['content' => [['text' => 'sk-'], ['text' => str_repeat('z', 24)]]]]]],
            'unicode width' => [['messages' => [['content' => 'ｐａｓｓｗｏｒｄ＝pretendvalue']]]],
            'nested json escaped name' => [['messages' => [['content' => '{"api_\\u006bey":"value"}']]]],
        ];
    }

    #[DataProvider('sensitivePayloads')]
    public function test_rejects_known_secrets_without_leaking_them(array $payload): void
    {
        try {
            app(SecretBoundary::class)->assertSafe($payload);
            self::fail('Expected rejection.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('AI data cannot be safely inspected for secrets.', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function test_accepts_public_information_and_rejects_uninspectable_values(): void
    {
        app(SecretBoundary::class)->assertSafe(['messages' => [['content' => ['name' => 'Пиротехника', 'facts' => [['topic' => 'products', 'value' => 'Доставка фейерверков']]]]]]);
        self::assertTrue(true);
        $this->expectException(InvalidArgumentException::class);
        app(SecretBoundary::class)->assertSafe(['messages' => [['content' => new stdClass]]]);
    }

    public function test_refuses_excessive_nesting(): void
    {
        $value = 'public';
        for ($i = 0; $i < 24; $i++) {
            $value = ['x' => $value];
        }
        $this->expectException(InvalidArgumentException::class);
        app(SecretBoundary::class)->assertSafe($value);
    }

    public static function discoveredBypasses(): array
    {
        return [
            'generic token field' => [['token' => 'synthetic-value']],
            'camel id token' => [['idToken' => 'synthetic-value']],
            'pwd field' => [['pwd' => 'synthetic-value']],
            'prefixed camel password' => [['userPassword' => 'synthetic-123']],
            'prefixed camel database password' => [['databasePassword' => 'synthetic-123']],
            'suffixed camel secret' => [['secretValue' => 'synthetic-123']],
            'acronym mixed camel' => [['serviceAPIKey' => 'synthetic-123']],
            'hidden formatting in camel' => [["secr\u{200b}etValue" => 'synthetic-123']],
            'quoted camel field in prose' => [['content' => 'Example: {"userPassword":"synthetic-123"}']],
            'quoted authorization field in prose' => [['content' => 'Example: {"Authorization":"synthetic-123"}']],
            'quoted secret field in prose' => [['content' => 'Example: {"secret":"synthetic-123"}']],
            'quoted assignment in text' => [['content' => 'Here is JSON: {"password":"synthetic-123"}']],
            'nested quoted JSON' => [['tool_calls' => [['arguments' => '{"message":"credentials: {\\"api_key\\":\\"synthetic-123\\"}"}']]]],
            'duplicated password key' => [['tool_calls' => [['arguments' => '{"password":"synthetic-123","password":""}']]]],
            'duplicated key null' => [['tool_calls' => [['arguments' => '{"api_key":"synthetic-123","api_key":null}']]]],
            'duplicate escaped equivalent' => [['tool_calls' => [['arguments' => '{"public":1,"pub\\u006cic":2}']]]],
            'nested duplicate' => [['tool_calls' => [['arguments' => '{"child":{"item":1,"item":2}}']]]],
            'malformed arguments' => [['tool_calls' => [['arguments' => '{"password":"synthetic-123']]]],
            'malformed camel tool context' => [['toolCalls' => [['arguments' => '{"product":"rocket"']]]],
            'malformed hidden tool context' => [["tool\u{200b}_calls" => [['arguments' => '{"product":"rocket"']]]],
            'malformed benign arguments' => [['tool_calls' => [['arguments' => '{"product":"rocket"']]]],
            'scalar tool arguments' => [['tool_calls' => [['arguments' => '"public"']]]],
            'non-string scalar tool arguments' => [['tool_calls' => [['arguments' => true]]]],
            'escaped bearer space' => [['content' => 'Bearer\\u0020syntheticvalue987654']],
            'escaped assignment name' => [['content' => 'api\\u005fkey=synthetic987654']],
            'json string scalar' => [['content' => '"Bearer\\u0020syntheticvalue987654"']],
            'AWS access key id' => [['content' => 'AKIA'.str_repeat('A', 16)]],
            'AWS temporary access key id' => [['content' => 'ASIA'.str_repeat('A', 16)]],
            'basic authorization' => [['content' => 'Authorization: Basic '.base64_encode('synthetic:pass123')]],
            'basic minimal username and password' => [['content' => 'Basic YTpi']],
            'basic empty password' => [['content' => 'Basic YTo=']],
            'basic empty username' => [['content' => 'Basic OmI=']],
            'basic only separator' => [['content' => 'Basic Og==']],
            'basic unpadded' => [['content' => 'Basic Og']],
            'percent encoded basic' => [['content' => 'Basic%20YTpi']],
            'unicode basic' => [['content' => 'Ｂａｓｉｃ　ＹＴｐｉ']],
            'html encoded basic' => [['content' => 'Basic&#32;YTpi']],
            'credential URL' => [['content' => 'https://synthetic:pass123@example.test/path']],
            'url-encoded credential URL' => [['content' => 'https%3A%2F%2Fsynthetic%3Apass123%40example.test']],
            'html-encoded credential assignment' => [['content' => 'password&#61;synthetic123']],
            'split secret across chunks' => [['content' => [['text' => 'sk-'], ['text' => 'ab'], ['text' => 'cd'], ['text' => 'efghijklmnopqrstuvwxyz']]]],
        ];
    }

    #[DataProvider('discoveredBypasses')]
    public function test_regressions_fail_closed_without_leaking_values(array $payload): void
    {
        try {
            app(SecretBoundary::class)->assertSafe($payload);
            self::fail('Expected safe rejection.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('AI data cannot be safely inspected for secrets.', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public static function publicExamples(): array
    {
        return [
            'normal public Russian content' => [['content' => 'Компания поставляет фейерверки, цены по запросу.']],
            'public English basic text' => [['content' => 'Basic configuration is available in the documentation.']],
            'basic plain word' => [['content' => 'Basic editing is available.']],
            'basic valid base64 without colon' => [['content' => 'Basic '.base64_encode('public')]],
            'basic invalid base64 text' => [['content' => 'Basic !!!']],
            'public design tokens' => [['tokens' => ['textColor' => '#123456']]],
            'unrelated key name' => [['passwordlessLogin' => true]],
            'similar short AWS prefix' => [['content' => 'AKIA1234 is a warehouse label.']],
            'tool arguments object' => [['tool_calls' => [['arguments' => '{"category":"fireworks","size":42}']]]],
            'same key in different nested objects' => [['tool_calls' => [['arguments' => '{"one":{"item":1},"two":{"item":2}}']]]],
            'escaped emoji' => [['tool_calls' => [['arguments' => '{"label":"\\uD83D\\uDE80"}']]]],
            'arguments outside tool context' => [['content' => ['arguments' => 'Доставка и ассортимент']]],
        ];
    }

    #[DataProvider('publicExamples')]
    public function test_reasonable_public_inputs_remain_usable(array $payload): void
    {
        app(SecretBoundary::class)->assertSafe($payload);
        self::assertTrue(true);
    }

    public function test_rejection_stops_a_fake_transport_and_trace_hides_direct_payload(): void
    {
        $payload = ['tool_calls' => [['arguments' => '{"password":"sensitive-synthetic-marker","password":""}']]];
        $calls = 0;
        try {
            app(SecretBoundary::class)->assertSafe($payload);
            $calls++;
            self::fail('Unsafe payload reached the fake transport.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(0, $calls);
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('sensitive-synthetic-marker', (string) $exception);
            $frame = $exception->getTrace()[0] ?? [];
            self::assertStringNotContainsString('sensitive-synthetic-marker', print_r($frame, true));
        }
    }

    public function test_successful_inspection_never_changes_the_original_payload(): void
    {
        $raw = '{"label":"\\uD83D\\uDE80","category":"fireworks"}';
        $payload = ['tool_calls' => [['arguments' => $raw]]];
        app(SecretBoundary::class)->assertSafe($payload);
        self::assertSame($raw, $payload['tool_calls'][0]['arguments']);
    }

    public function test_short_basic_rejection_exposes_neither_input_nor_previous_exception(): void
    {
        try {
            app(SecretBoundary::class)->assertSafe(['content' => 'Basic YTpi']);
            self::fail('Expected rejection.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('AI data cannot be safely inspected for secrets.', $exception->getMessage());
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('YTpi', print_r($exception->getTrace()[0] ?? [], true));
        }
    }
}
