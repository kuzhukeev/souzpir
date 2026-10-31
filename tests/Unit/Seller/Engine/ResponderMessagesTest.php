<?php

namespace Tests\Unit\Seller\Engine;

use App\Seller\Engine\ResponderMessages;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SensitiveParameter;
use stdClass;

final class ResponderMessagesTest extends TestCase
{
    public function test_history_uses_last_twelve_entries_and_only_client_and_agent_roles(): void
    {
        $history = [];
        for ($i = 1; $i <= 14; $i++) {
            $history[] = ['role' => $i === 5 ? 'system' : ($i % 2 ? 'client' : 'agent'), 'text' => 'message-'.$i];
        }
        $result = (new ResponderMessages)->compose('synthetic prompt', $history, []);
        self::assertSame('not_authorization', $result['status']);
        self::assertSame(['role' => 'system', 'content' => 'synthetic prompt'], $result['messages'][0]);
        self::assertSame(['role' => 'user', 'content' => 'message-3'], $result['messages'][1]);
        self::assertCount(12, $result['messages']);
        self::assertSame(['role' => 'assistant', 'content' => 'message-14'], end($result['messages']));
        self::assertSame([], $result['tools']);
        self::assertNull($result['toolChoice']);
    }

    public function test_original_tool_definitions_are_only_attached_before_results_and_regeneration(): void
    {
        $builder = new ResponderMessages;
        $original = $builder->compose('prompt', [], ['send_price', 'mark_do_not_disturb']);
        self::assertSame('auto', $original['toolChoice']);
        self::assertSame('send_price', $original['tools'][0]['function']['name']);
        self::assertInstanceOf(stdClass::class, $original['tools'][1]['function']['parameters']['properties']);
        self::assertStringContainsString('"properties":{}', json_encode($original['tools'], JSON_THROW_ON_ERROR));
        $result = $builder->compose('prompt', [], ['send_price'], [['ok' => true, 'message' => 'синтетика']], ['первое', 'второе']);
        self::assertSame([], $result['tools']);
        self::assertNull($result['toolChoice']);
        self::assertSame("[РЕЗУЛЬТАТЫ ИНСТРУМЕНТОВ, клиент их не видит]\n[\n    {\n        \"ok\": true,\n        \"message\": \"синтетика\"\n    }\n]\n\nТеперь напиши финальную реплику клиенту. Инструменты больше не вызывай.", $result['messages'][1]['content']);
        self::assertSame('[ПЕРЕДЕЛАЙ РЕПЛИКУ. Замечания проверки: первое; второе. Напиши заново, только текст реплики.]', $result['messages'][2]['content']);
        self::assertSame([], $builder->compose('prompt', [], ['send_price'], [], ['переделать'])['tools']);
    }

    public function test_malformed_inputs_fail_without_exposing_source_or_chaining(): void
    {
        $builder = new ResponderMessages;
        try {
            $builder->compose('PRIVATE-PROMPT', [['role' => 'client', 'text' => ['PRIVATE-CONTACT']]], []);
            self::fail('Malformed history must fail.');
        } catch (LogicException $error) {
            self::assertSame('Responder messages could not be safely composed.', $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('PRIVATE-PROMPT', $error->getTraceAsString());
            self::assertStringNotContainsString('PRIVATE-CONTACT', $error->getTraceAsString());
        }
        foreach (array_slice((new ReflectionMethod(ResponderMessages::class, 'compose'))->getParameters(), 0, 5) as $parameter) {
            self::assertNotEmpty($parameter->getAttributes(SensitiveParameter::class));
        }
    }

    public function test_input_sizes_and_nested_structures_are_bounded_before_encoding(): void
    {
        $builder = new ResponderMessages;
        self::assertSame('not_authorization', $builder->compose(str_repeat('x', 262144), [], [])['status']);
        $deep = 'x';
        for ($i = 0; $i < 14; $i++) {
            $deep = ['nested' => $deep];
        }
        $cycle = new stdClass;
        $cycle->self = $cycle;
        $cases = [
            [str_repeat('x', 262145), []],
            [str_repeat('x', 262144), ['large' => str_repeat('y', 262144)]],
            ['prompt', array_fill(0, 4100, 1)],
            ['prompt', $deep],
            ['prompt', [$cycle]],
            ['prompt', [str_repeat('k', 262145) => 'value']],
        ];
        foreach ($cases as [$prompt, $tools]) {
            try {
                $builder->compose($prompt, [], [], $tools);
                self::fail('Oversized or recursive input must be rejected.');
            } catch (LogicException $error) {
                self::assertSame('Responder messages could not be safely composed.', $error->getMessage());
                self::assertNull($error->getPrevious());
            }
        }
    }

    public function test_serializable_stdclass_subclass_cannot_execute_code_during_composition(): void
    {
        $executed = false;
        $value = new class($executed) extends stdClass implements \JsonSerializable
        {
            private mixed $executed;

            public function __construct(bool &$executed)
            {
                $this->executed = &$executed;
            }

            public function jsonSerialize(): mixed
            {
                $this->executed = true;

                return str_repeat('x', 10000000);
            }
        };
        try {
            (new ResponderMessages)->compose('prompt', [], [], [$value]);
            self::fail('Serializable object must be rejected before encoding.');
        } catch (LogicException $error) {
            self::assertSame('Responder messages could not be safely composed.', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
        self::assertFalse($executed);
        $result = (new ResponderMessages)->compose('prompt', [], [], [new stdClass]);
        self::assertStringContainsString('{}', $result['messages'][1]['content']);
    }

    public function test_invalid_utf8_is_rejected_in_all_inputs_even_when_not_in_output(): void
    {
        $private = "PRIVATE-WITNESS\xff";
        foreach ([
            [$private, [], [], [], []],
            ['prompt', [['role' => 'system', 'text' => $private]], [], [], []],
            ['prompt', [], [$private], [], []],
            ['prompt', [], [], [$private => 'value'], []],
            ['prompt', [], [], [], [$private]],
        ] as [$prompt, $history, $tools, $results, $issues]) {
            try {
                (new ResponderMessages)->compose($prompt, $history, $tools, $results, $issues);
                self::fail('Invalid UTF-8 accepted.');
            } catch (LogicException $error) {
                self::assertSame('Responder messages could not be safely composed.', $error->getMessage());
                self::assertNull($error->getPrevious());
                self::assertStringNotContainsString('PRIVATE-WITNESS', (string) $error);
            }
        }
    }
}
