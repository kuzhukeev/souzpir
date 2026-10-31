<?php

namespace Tests\Unit\Seller\Engine;

use App\Seller\Engine\GuardRequest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SensitiveParameter;

final class GuardRequestTest extends TestCase
{
    public function test_matches_donor_message_order_and_strict_guard_schema(): void
    {
        $checksum = str_repeat('a', 64);
        $request = (new GuardRequest)->build('Синтетический системный шаблон', 'Синтетический ответ', 'fixture-model', 'v1', $checksum);
        self::assertSame([
            ['role' => 'system', 'content' => 'Синтетический системный шаблон'],
            ['role' => 'user', 'content' => 'Синтетический ответ'],
        ], $request->messages);
        self::assertSame('fixture-model', $request->model);
        self::assertSame(0.0, $request->temperature);
        self::assertSame(300, $request->maxTokens);
        self::assertSame('v1', $request->promptVersion);
        self::assertSame($checksum, $request->promptChecksum);
        self::assertSame([], $request->tools);
        self::assertNull($request->toolChoice);
        self::assertSame(['type' => 'json_schema', 'json_schema' => [
            'name' => 'guard', 'strict' => true, 'schema' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['ok', 'issues'],
                'properties' => ['ok' => ['type' => 'boolean'], 'issues' => ['type' => 'array', 'items' => ['type' => 'string']]],
            ],
        ]], $request->responseFormat);
    }

    public function test_combined_size_limit_and_empty_reply_are_explicit(): void
    {
        $checksum = str_repeat('a', 64);
        $request = (new GuardRequest)->build(str_repeat('x', 262144), '', 'm', 'v1', $checksum);
        self::assertSame('', $request->messages[1]['content']);
        $this->assertRejected(str_repeat('x', 262144), 'x', 'm', 'v1', $checksum);
    }

    public function test_utf8_byte_boundaries_and_request_json_are_preserved(): void
    {
        $prompt = str_repeat('я', 131071);
        $reply = 'я';
        $model = str_repeat('м', 128);
        $version = str_repeat('в', 64);
        $request = (new GuardRequest)->build($prompt, $reply, $model, $version, str_repeat('a', 64));
        self::assertSame(262144, strlen($request->messages[0]['content']) + strlen($request->messages[1]['content']));
        self::assertSame($model, $request->model);
        self::assertSame($version, $request->promptVersion);
        self::assertSame($request->toArray(), json_decode(json_encode($request->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, 512, JSON_THROW_ON_ERROR));
        $this->assertRejected($prompt, $reply.'x', $model, $version, str_repeat('a', 64));
    }

    public function test_invalid_utf8_prompt_identity_and_overlong_metadata_fail_closed(): void
    {
        $checksum = str_repeat('a', 64);
        foreach ([
            ["\xff", 'reply', 'm', 'v1', $checksum],
            ['prompt', "\xff", 'm', 'v1', $checksum],
            ['prompt', 'reply', "\xff", 'v1', $checksum],
            ['prompt', 'reply', 'm', "\xff", $checksum],
            ['prompt', 'reply', ' ', 'v1', $checksum],
            ['prompt', 'reply', str_repeat('m', 257), 'v1', $checksum],
            ['prompt', 'reply', 'm', '', $checksum],
            ['prompt', 'reply', 'm', str_repeat('v', 129), $checksum],
            ['prompt', 'reply', 'm', 'v1', str_repeat('A', 64)],
            ['prompt', 'reply', 'm', 'v1', str_repeat('a', 63)],
            ['prompt', 'reply', 'm', 'v1', 'synthetic-private-witness'],
            ['synthetic-private-witness', 'synthetic-private-witness', 'm', '', $checksum],
        ] as [$prompt, $reply, $model, $version, $hash]) {
            $this->assertRejected($prompt, $reply, $model, $version, $hash);
        }
    }

    public function test_every_request_input_is_sensitive_in_exception_traces(): void
    {
        foreach ((new ReflectionMethod(GuardRequest::class, 'build'))->getParameters() as $parameter) {
            self::assertNotEmpty($parameter->getAttributes(SensitiveParameter::class));
        }
    }

    private function assertRejected(#[SensitiveParameter] string $prompt, #[SensitiveParameter] string $reply, #[SensitiveParameter] string $model, #[SensitiveParameter] string $version, #[SensitiveParameter] string $checksum): void
    {
        try {
            (new GuardRequest)->build($prompt, $reply, $model, $version, $checksum);
            self::fail('Invalid guard request was accepted.');
        } catch (InvalidArgumentException $error) {
            self::assertSame('Guard request could not be safely prepared.', $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('synthetic-private-witness', (string) $error);
            self::assertStringNotContainsString('synthetic-private-witness', json_encode($error->getTrace(), JSON_THROW_ON_ERROR));
        }
    }
}
