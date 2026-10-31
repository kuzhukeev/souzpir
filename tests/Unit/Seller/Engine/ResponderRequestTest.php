<?php

namespace Tests\Unit\Seller\Engine;

use App\Seller\Engine\ResponderRequest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;
use stdClass;

final class ResponderRequestTest extends TestCase
{
    public function test_request_matches_synthetic_captures_from_prototype_without_executing_tools(): void
    {
        $json = file_get_contents(__DIR__.'/fixtures/responder-prototype-synthetic.json');
        self::assertIsString($json);
        $cases = $this->normalize(json_decode($json, false, 512, JSON_THROW_ON_ERROR));
        self::assertCount(4, $cases);
        foreach ($cases as $case) {
            $request = (new ResponderRequest)->build(
                'СИСТЕМНЫЙ ШАБЛОН', $case['history'], $case['allowedTools'], 'synthetic-model', 0.7,
                'v1', str_repeat('a', 64), $case['toolResults'], $case['guardIssues'], $case['historyTurns'],
            );
            self::assertSame($case['expected']['messages'], $request->messages, $case['name']);
            self::assertSame($case['expected']['tools'], array_column(array_column($request->tools, 'function'), 'name'), $case['name']);
            self::assertSame($case['expected']['toolChoice'], $request->toolChoice, $case['name']);
            self::assertSame('synthetic-model', $request->model);
            self::assertSame(0.7, $request->temperature);
            self::assertSame(1200, $request->maxTokens);
            self::assertSame('v1', $request->promptVersion);
            self::assertSame(str_repeat('a', 64), $request->promptChecksum);
        }
    }

    public function test_empty_schema_objects_survive_request_representation(): void
    {
        $request = (new ResponderRequest)->build('prompt', [], ['mark_do_not_disturb', 'send_requisites'], 'synthetic-model', 0.7, 'v1', str_repeat('a', 64));
        $json = json_encode($request->toArray(), JSON_THROW_ON_ERROR);
        self::assertSame(2, substr_count($json, '"properties":{}'));
        self::assertStringNotContainsString('"properties":[]', $json);
    }

    public function test_invalid_parameters_and_unserializable_results_fail_without_leaking_private_input(): void
    {
        foreach ([
            ['v1', str_repeat('a', 64), 'synthetic-model', NAN, []],
            ['', str_repeat('a', 64), 'synthetic-model', 0.7, []],
            ['v1', str_repeat('z', 64), 'synthetic-model', 0.7, []],
            ['v1', str_repeat('a', 64), '', 0.7, []],
            ['v1', str_repeat('a', 64), 'synthetic-model', 0.7, [new class implements \JsonSerializable
            {
                public function jsonSerialize(): mixed
                {
                    throw new \RuntimeException('synthetic-private-witness');
                }
            }]],
            ["\xff", str_repeat('a', 64), 'synthetic-model', 0.7, []],
            ['v1', str_repeat('a', 64), "\xff", 0.7, []],
        ] as [$version, $checksum, $model, $temperature, $results]) {
            $this->assertRejected($version, $checksum, $model, $temperature, $results);
        }
    }

    public function test_invalid_utf8_in_prepared_messages_is_rejected_before_request_creation(): void
    {
        foreach ([
            ["\xff", []],
            ['prompt', [['role' => 'client', 'text' => "\xff"]]],
        ] as [$prompt, $history]) {
            try {
                (new ResponderRequest)->build($prompt, $history, [], 'synthetic-model', 0.7, 'v1', str_repeat('a', 64));
                self::fail('Invalid UTF-8 accepted.');
            } catch (InvalidArgumentException $error) {
                self::assertSame('Responder request could not be safely prepared.', $error->getMessage());
                self::assertNull($error->getPrevious());
            }
        }
    }

    private function assertRejected(#[SensitiveParameter] string $version, #[SensitiveParameter] string $checksum, #[SensitiveParameter] string $model, #[SensitiveParameter] float $temperature, #[SensitiveParameter] array $results): void
    {
        try {
            (new ResponderRequest)->build('synthetic-private-witness', [], [], $model, $temperature, $version, $checksum, $results);
            self::fail('Invalid request accepted.');
        } catch (InvalidArgumentException $error) {
            self::assertSame('Responder request could not be safely prepared.', $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('synthetic-private-witness', (string) $error);
            self::assertStringNotContainsString('synthetic-private-witness', json_encode($error->getTrace(), JSON_THROW_ON_ERROR));
        }
    }

    /** Preserve JSON empty objects while mapping fixture objects to PHP arrays. */
    private function normalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $fields = get_object_vars($value);
            if ($fields === []) {
                return $value;
            }

            return array_map($this->normalize(...), $fields);
        }

        return is_array($value) ? array_map($this->normalize(...), $value) : $value;
    }
}
