<?php

namespace Tests\Unit\Ai;

use App\Ai\Protected\RawToolArguments;
use App\Privacy\Secrets\JsonObjectInspector;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SensitiveParameter;

final class RawToolArgumentsTest extends TestCase
{
    public function test_accepts_valid_objects_without_confusing_nested_values_or_distinct_scopes(): void
    {
        $check = new RawToolArguments;
        foreach ([
            '{}', " \t\r\n{\"items\":[{},[],{\"a\":1},{\"a\":2}],\"a\":3}",
            '{"text":"not a key: \"a\": {}", "escaped\"key":true, "0":0, "00":1}',
            '{"ключ":"значение","\\ud83d\\ude00":1,"é":2,"é":3}',
        ] as $index => $json) {
            self::assertTrue($check->accepts($json), 'Case '.$index);
        }
    }

    public function test_rejects_lists_scalars_invalid_json_and_duplicate_keys_at_any_depth(): void
    {
        $check = new RawToolArguments;
        foreach ([
            '', '[]', '[{}]', 'null', 'true', '1', '"{}"', '{', '{"x":1,}',
            "\xEF\xBB\xBF{}", "{\"x\":\"\xFF\"}", '{"x":NaN}',
            '{"a":1,"a":2}', '{"a":1,"\\u0061":2}',
            '{"ключ":1,"\\u043a\\u043b\\u044e\\u0447":2}',
            '{"😀":1,"\\ud83d\\ude00":2}', '{"0":1,"\\u0030":2}',
            '{"outer":{"a":1,"a":2}}', '{"outer":[{"a":1,"\\u0061":2}]}',
            str_repeat('{"a":', 30).'0'.str_repeat('}', 30),
        ] as $json) {
            self::assertFalse($check->accepts($json));
        }
    }

    public function test_failure_is_only_a_boolean_and_raw_input_is_sensitive_in_both_frames(): void
    {
        $json = '{"private-marker-239":"secret", "private-marker-239":2}';
        self::assertSame(false, (new RawToolArguments)->accepts($json));
        foreach ([RawToolArguments::class => 'accepts', JsonObjectInspector::class => 'decode'] as $class => $method) {
            $parameter = (new ReflectionMethod($class, $method))->getParameters()[0];
            self::assertNotEmpty($parameter->getAttributes(SensitiveParameter::class));
        }
        try {
            (new JsonObjectInspector)->decode($json);
            self::fail('The duplicate must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('', $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('private-marker-239', json_encode($error->getTrace(), JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('secret', $error->getTraceAsString());
        }
    }
}
