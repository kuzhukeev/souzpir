<?php

namespace Tests\Unit\Seller\Engine;

use App\Seller\Engine\ToolArguments;
use App\Seller\Engine\ToolCatalog;
use PHPUnit\Framework\TestCase;

final class ToolGoldenTest extends TestCase
{
    public function test_all_prototype_tool_definitions_match_the_read_only_donor_fixture(): void
    {
        // Extracted directly from the donor's ToolRunner::definitions(), without executing run().
        $fixture = file_get_contents(__DIR__.'/fixtures/prototype-tool-definitions.json');
        self::assertIsString($fixture);
        $expected = json_decode($fixture, false, 512, JSON_THROW_ON_ERROR);
        $actual = json_decode(json_encode(ToolCatalog::definitions(), JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        self::assertEquals($expected, $actual);
        self::assertCount(9, (array) $actual);
        self::assertInstanceOf(\stdClass::class, $actual->mark_do_not_disturb->function->parameters->properties);
        self::assertSame([], get_object_vars($actual->mark_do_not_disturb->function->parameters->properties));
        self::assertInstanceOf(\stdClass::class, $actual->send_requisites->function->parameters->properties);
        self::assertSame([], get_object_vars($actual->send_requisites->function->parameters->properties));
    }

    public function test_synthetic_shape_boundaries_for_every_prototype_tool_do_not_execute_it(): void
    {
        $checker = new ToolArguments;
        $cases = [
            ['send_price', [], true],
            ['send_price', ['email' => '', 'messenger' => 'telegram', 'pricelists' => ['общий', 'volk']], true],
            ['send_price', ['pricelists' => ['общий', null]], false],
            ['calc_delivery', ['city' => '', 'weight_kg' => 0], true],
            ['calc_delivery', ['weight_kg' => 5], false],
            ['calc_delivery', ['city' => 'Москва', 'weight_kg' => '5'], false],
            ['set_followup', ['datetime' => 'завтра', 'reason' => 'обратная связь'], true],
            ['set_followup', ['reason' => 'обратная связь'], false],
            ['create_promise', ['text' => ''], true],
            ['create_promise', ['text' => null], false],
            ['escalate', ['reason' => '', 'summary' => 'контекст'], true],
            ['escalate', ['summary' => 'контекст'], false],
            ['mark_do_not_disturb', [], true],
            ['mark_do_not_disturb', ['extra' => true], false],
            ['check_stock', ['query' => 'салют'], true],
            ['check_stock', [], false],
            ['log_unknown_question', ['question' => 'вопрос'], true],
            ['log_unknown_question', ['question' => ['вопрос']], false],
            ['send_requisites', [], true],
            ['send_requisites', ['unexpected' => 'value'], false],
        ];
        foreach ($cases as [$name, $arguments, $valid]) {
            self::assertSame(
                $valid ? ['valid' => true, 'code' => 'shape_only'] : ['valid' => false, 'code' => 'invalid_tool_arguments'],
                $checker->check($name, $arguments),
                $name,
            );
        }
    }

    public function test_input_limits_are_explicit_and_fail_closed(): void
    {
        $checker = new ToolArguments;
        // Key bytes count towards the 16 KiB budget; exactly at the limit is accepted.
        self::assertTrue($checker->check('check_stock', ['query' => str_repeat('x', 16384 - strlen('query'))])['valid']);
        self::assertFalse($checker->check('check_stock', ['query' => str_repeat('x', 16385 - strlen('query'))])['valid']);
        self::assertTrue($checker->check('send_price', ['pricelists' => array_fill(0, 100, 'x')])['valid']);
        self::assertFalse($checker->check('send_price', ['pricelists' => array_fill(0, 101, 'x')])['valid']);
        self::assertFalse($checker->check('calc_delivery', ['city' => 'Москва', 'weight_kg' => NAN])['valid']);
        self::assertFalse($checker->check('send_price', ['pricelists' => [1 => 'общий']])['valid']);
    }
}
