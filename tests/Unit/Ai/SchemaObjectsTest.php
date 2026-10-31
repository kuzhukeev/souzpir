<?php

namespace Tests\Unit\Ai;

use App\Ai\CanonicalJson;
use App\Ai\ContactMasker;
use App\Ai\Drivers\FakeDriver;
use App\Ai\Drivers\OpenRouterDriver;
use App\Ai\SchemaObjects;
use App\Privacy\ContactBoundary;
use App\Privacy\Secrets\SecretBoundary;
use InvalidArgumentException;
use stdClass;
use Tests\TestCase;

final class SchemaObjectsTest extends TestCase
{
    private function body(): array
    {
        return [
            'messages' => [['role' => 'user', 'content' => 'Публичная информация']],
            'model' => 'example/model', 'temperature' => 0.3,
            'tools' => [['type' => 'function', 'function' => ['name' => 'search', 'parameters' => [
                'type' => 'object', 'properties' => new stdClass, 'required' => [],
            ]]]],
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'result', 'schema' => [
                'type' => 'object', 'properties' => new stdClass,
            ]]],
        ];
    }

    public function test_schema_objects_survive_privacy_boundaries_and_wire_format(): void
    {
        $body = $this->body();
        (new SecretBoundary)->assertSafe(SchemaObjects::forInspection($body));
        $masked = (new ContactMasker)->request($body);
        $inspection = SchemaObjects::forInspection($masked);
        (new ContactBoundary)->assertSafe($inspection);
        (new SecretBoundary)->assertSafe($inspection);
        $json = json_encode((new OpenRouterDriver)->wireBody($masked), JSON_THROW_ON_ERROR);
        self::assertSame(2, substr_count($json, '"properties":{}'));
        self::assertStringNotContainsString('"properties":[]', $json);
        $masked['model'] = 'anthropic/example';
        $anthropic = (new OpenRouterDriver)->wireBody($masked);
        self::assertArrayNotHasKey('response_format', $anthropic);
        self::assertStringContainsString('"properties":{}', end($anthropic['messages'])['content']);
    }

    public function test_hash_and_fake_fixture_preserve_object_array_distinction(): void
    {
        $body = (new ContactMasker)->request($this->body());
        $changed = $body;
        $changed['tools'][0]['function']['parameters']['properties'] = [];
        self::assertNotSame(CanonicalJson::hash($body), CanonicalJson::hash($changed));
        self::assertSame(CanonicalJson::hash(['b' => 2, 'a' => new stdClass]), CanonicalJson::hash(['a' => new stdClass, 'b' => 2]));
        $directory = base_path('.local/runtime/testing/ai-schema-'.bin2hex(random_bytes(8)));
        mkdir($directory, 0700, true);
        config(['features.E0-05.defaults.fixture_directory' => $directory]);
        file_put_contents($directory.'/'.CanonicalJson::hash($body).'.json', json_encode(['result' => ['content' => 'fake result']], JSON_THROW_ON_ERROR));
        self::assertSame(['content' => 'fake result'], (new FakeDriver)->complete($body));
    }

    public function test_large_vision_payload_keeps_the_existing_canonical_hash(): void
    {
        $slot = ['id' => 'slot', 'provider' => 'example', 'question_n' => 1, 'qid' => 'question',
            'intent' => 'purchase', 'run_no' => 1, 'status' => 'completed', 'protocol_valid' => true,
            'fact' => null, 'mentions' => [], 'mentions_complete' => false, 'sources' => []];
        $data = ['metrics' => ['slots' => array_fill(0, 2000, $slot)]];
        $legacy = static function (mixed $value) use (&$legacy): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map($legacy, $value);
        };
        $expected = hash('sha256', json_encode($legacy($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        self::assertSame($expected, CanonicalJson::hash($data));
    }

    public function test_rejects_uninspectable_objects_without_invoking_serializers(): void
    {
        $spy = new class implements \JsonSerializable
        {
            public bool $called = false;

            public function jsonSerialize(): mixed
            {
                $this->called = true;

                return [];
            }
        };
        $nonempty = (object) ['secret' => 'never serialize'];
        $subclass = new class extends stdClass {};
        foreach ([$spy, $nonempty, $subclass] as $object) {
            $body = $this->body();
            $body['tools'][0]['function']['parameters']['properties'] = $object;
            try {
                SchemaObjects::forInspection($body);
                self::fail('Unsupported object was accepted.');
            } catch (InvalidArgumentException) {
                self::assertFalse($spy->called);
            }
            try {
                (new OpenRouterDriver)->wireBody($body);
                self::fail('Unsupported object reached the wire formatter.');
            } catch (InvalidArgumentException) {
                self::assertFalse($spy->called);
            }
            try {
                (new FakeDriver)->complete($body);
                self::fail('Unsupported object reached the fake driver.');
            } catch (InvalidArgumentException) {
                self::assertFalse($spy->called);
            }
            try {
                CanonicalJson::hash(['value' => $object]);
                self::fail('Unsupported object was hashed.');
            } catch (InvalidArgumentException) {
                self::assertFalse($spy->called);
            }
        }
        $body = $this->body();
        $body['messages'][0]['content'] = new stdClass;
        $this->expectException(InvalidArgumentException::class);
        (new ContactMasker)->request($body);
    }

    public function test_string_tool_arguments_keep_json_shapes_and_mask_contacts(): void
    {
        $body = $this->body();
        $body['messages'][] = ['role' => 'assistant', 'content' => '', 'tool_calls' => [[
            'id' => 'call-1', 'type' => 'function', 'function' => [
                'name' => 'search', 'arguments' => '{"nested":{},"items":[{},[]],"email":"buyer@example.test"}',
            ],
        ]]];
        (new SecretBoundary)->assertSafe(SchemaObjects::forInspection($body));
        $masked = (new ContactMasker)->request($body);
        self::assertSame('{"nested":{},"items":[{},[]],"email":"[EMAIL]"}', $masked['messages'][1]['tool_calls'][0]['function']['arguments']);
        (new ContactBoundary)->assertSafe(SchemaObjects::forInspection($masked));
        (new SecretBoundary)->assertSafe(SchemaObjects::forInspection($masked));
        $body['messages'][1]['tool_calls'][0]['function']['arguments'] = '{"nested":{},"api_key":"synthetic-secret"}';
        $this->expectException(InvalidArgumentException::class);
        (new SecretBoundary)->assertSafe(SchemaObjects::forInspection($body));
    }

    public function test_json_tool_arguments_with_duplicate_keys_still_fail_before_masking(): void
    {
        $body = $this->body();
        $body['messages'][] = ['role' => 'assistant', 'content' => '', 'tool_calls' => [[
            'id' => 'call-1', 'type' => 'function', 'function' => [
                'name' => 'search', 'arguments' => '{"nested":{},"nested":{"password":"secret"}}',
            ],
        ]]];
        $this->expectException(InvalidArgumentException::class);
        (new SecretBoundary)->assertSafe(SchemaObjects::forInspection($body));
    }

    public function test_inspection_does_not_modify_references_and_cycles_are_rejected(): void
    {
        $empty = new stdClass;
        $body = $this->body();
        $body['tools'][0]['function']['parameters']['properties'] = &$empty;
        $inspection = SchemaObjects::forInspection($body);
        self::assertSame([], $inspection['tools'][0]['function']['parameters']['properties']);
        self::assertSame($empty, $body['tools'][0]['function']['parameters']['properties']);
        self::assertInstanceOf(stdClass::class, $empty);
        CanonicalJson::hash($body);
        self::assertSame($empty, $body['tools'][0]['function']['parameters']['properties']);
        $cycle = [];
        $cycle['recursive'] = &$cycle;
        try {
            SchemaObjects::forInspection(['response_format' => ['json_schema' => ['schema' => $cycle]]]);
            self::fail('Cyclic data was accepted.');
        } catch (InvalidArgumentException) {
            self::assertArrayHasKey('recursive', $cycle);
        }
        $this->expectException(InvalidArgumentException::class);
        CanonicalJson::hash(['cycle' => $cycle]);
    }

    public function test_failure_trace_does_not_contain_schema_keys(): void
    {
        $body = $this->body();
        $body['response_format']['json_schema']['schema']['private-marker-9382'] = (object) ['key' => 'value'];
        try {
            SchemaObjects::forInspection($body);
            self::fail('Nonempty object was accepted.');
        } catch (InvalidArgumentException $error) {
            self::assertStringNotContainsString('private-marker-9382', json_encode($error->getTrace(), JSON_THROW_ON_ERROR));
        }
    }
}
