<?php

namespace Tests\Unit\Seller\Engine;

use App\Seller\Engine\ToolArguments;
use App\Seller\Engine\ToolCatalog;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;

final class ToolArgumentsTest extends TestCase
{
    public function test_all_nine_donor_tool_shapes_can_be_checked_without_executing_a_tool(): void
    {
        $cases = [
            'send_price' => ['email' => 'buyer@example.test', 'pricelists' => ['общий']],
            'calc_delivery' => ['city' => 'Тестовый город', 'weight_kg' => 12.5],
            'set_followup' => ['datetime' => 'завтра'],
            'create_promise' => ['text' => 'уточнить'],
            'escalate' => ['reason' => 'нужен менеджер'],
            'mark_do_not_disturb' => [],
            'check_stock' => ['query' => 'товар'],
            'log_unknown_question' => ['question' => 'вопрос'],
            'send_requisites' => [],
        ];
        foreach ($cases as $name => $arguments) {
            self::assertSame(['valid' => true, 'code' => 'shape_only'], (new ToolArguments)->check($name, $arguments), $name);
        }
    }

    public function test_wrong_shapes_are_rejected_without_echoing_private_values(): void
    {
        $cases = [
            ['unknown', []], ['create_promise', []], ['calc_delivery', ['city' => 5]],
            ['calc_delivery', ['city' => 'test', 'weight_kg' => INF]],
            ['send_price', ['pricelists' => ['a', 2]]], ['send_price', ['email' => 'private@example.test', 'extra' => 'secret']],
            ['mark_do_not_disturb', ['unexpected' => 'secret']], ['send_requisites', new \stdClass],
            ['check_stock', ['query' => str_repeat('x', 16385)]], ['check_stock', ['query' => "\xff"]],
        ];
        foreach ($cases as [$name, $arguments]) {
            $result = (new ToolArguments)->check($name, $arguments);
            self::assertSame(['valid' => false, 'code' => 'invalid_tool_arguments'], $result);
            self::assertStringNotContainsString('secret', json_encode($result, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('private@example.test', json_encode($result, JSON_THROW_ON_ERROR));
        }
    }

    public function test_total_input_and_list_lengths_are_bounded(): void
    {
        $checker = new ToolArguments;
        self::assertFalse($checker->check('send_price', ['pricelists' => array_fill(0, 101, 'x')])['valid']);
        self::assertFalse($checker->check('send_price', ['pricelists' => array_fill(0, 100, str_repeat('x', 200))])['valid']);
        self::assertTrue($checker->check('send_price', ['pricelists' => []])['valid']);
    }

    public function test_all_tools_reject_extra_fields_and_each_required_field_is_enforced(): void
    {
        $checker = new ToolArguments;
        foreach (ToolCatalog::definitions() as $name => $definition) {
            $parameters = $definition['function']['parameters'];
            $valid = [];
            foreach ($parameters['required'] as $key) {
                $valid[$key] = 'synthetic';
            }
            self::assertTrue($checker->check($name, $valid)['valid'], $name);
            self::assertFalse($checker->check($name, [...$valid, 'extra' => 'PRIVATE-SECRET'])['valid'], $name);
            foreach ($parameters['required'] as $key) {
                $missing = $valid;
                unset($missing[$key]);
                self::assertFalse($checker->check($name, $missing)['valid'], $name.':'.$key);
            }
        }
    }

    public function test_each_string_property_and_list_item_rejects_invalid_utf8(): void
    {
        $checker = new ToolArguments;
        foreach (ToolCatalog::definitions() as $name => $definition) {
            $parameters = $definition['function']['parameters'];
            $properties = (array) $parameters['properties'];
            $base = [];
            foreach ($parameters['required'] as $key) {
                $base[$key] = 'synthetic';
            }
            foreach ($properties as $key => $schema) {
                if ($schema['type'] === 'string') {
                    self::assertFalse($checker->check($name, [...$base, $key => "private\xff"])['valid'], $name.':'.$key);
                } elseif ($schema['type'] === 'array') {
                    self::assertFalse($checker->check($name, [...$base, $key => ["private\xff"]])['valid'], $name.':'.$key);
                }
            }
        }
    }

    public function test_decoded_shape_limits_and_nonfinite_numbers_are_rejected(): void
    {
        $checker = new ToolArguments;
        $limit = 16384 - strlen('query');
        $atLimit = str_repeat('я', intdiv($limit, 2)).str_repeat('x', $limit % 2);
        self::assertSame(['valid' => true, 'code' => 'shape_only'], $checker->check('check_stock', ['query' => $atLimit]));
        self::assertSame(['valid' => false, 'code' => 'invalid_tool_arguments'], $checker->check('check_stock', ['query' => $atLimit.'x']));
        foreach ([NAN, INF, -INF] as $value) {
            self::assertFalse($checker->check('calc_delivery', ['city' => 'x', 'weight_kg' => $value])['valid']);
        }
        self::assertFalse($checker->check('send_price', ['pricelists' => [1 => 'x']])['valid']);
        self::assertFalse($checker->check('send_price', ['pricelists' => array_fill(0, 101, '')])['valid']);
        self::assertFalse($checker->check("private\xff", [])['valid']);
    }

    public function test_rejections_do_not_serialize_or_expose_private_arguments(): void
    {
        $called = false;
        $object = new class($called) implements \JsonSerializable
        {
            private mixed $called;

            public function __construct(bool &$called)
            {
                $this->called = &$called;
            }

            public function jsonSerialize(): mixed
            {
                $this->called = true;

                return 'PRIVATE-SECRET';
            }
        };
        $result = (new ToolArguments)->check('check_stock', ['query' => $object]);
        self::assertFalse($called);
        self::assertSame(['valid' => false, 'code' => 'invalid_tool_arguments'], $result);
        self::assertStringNotContainsString('PRIVATE-SECRET', json_encode($result, JSON_THROW_ON_ERROR));
        foreach ((new \ReflectionMethod(ToolArguments::class, 'check'))->getParameters() as $parameter) {
            self::assertNotEmpty($parameter->getAttributes(SensitiveParameter::class));
        }
    }

    public function test_decoded_arrays_cannot_prove_json_root_type_or_absence_of_duplicate_keys(): void
    {
        $checker = new ToolArguments;
        $emptyObject = json_decode('{}', true, 512, JSON_THROW_ON_ERROR);
        $emptyList = json_decode('[]', true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($emptyObject, $emptyList);
        self::assertSame(['valid' => true, 'code' => 'shape_only'], $checker->check('send_requisites', $emptyObject));
        self::assertSame($checker->check('send_requisites', $emptyObject), $checker->check('send_requisites', $emptyList));

        $duplicates = json_decode('{"text":"first","text":"second"}', true, 512, JSON_THROW_ON_ERROR);
        $single = json_decode('{"text":"second"}', true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($single, $duplicates);
        self::assertSame(['valid' => true, 'code' => 'shape_only'], $checker->check('create_promise', $duplicates));
    }
}
