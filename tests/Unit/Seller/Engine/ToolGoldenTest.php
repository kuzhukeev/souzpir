<?php

namespace Tests\Unit\Seller\Engine;

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
}
