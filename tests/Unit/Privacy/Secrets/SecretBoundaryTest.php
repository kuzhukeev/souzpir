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
}
