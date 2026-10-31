<?php

namespace Tests\Unit\Seller\Engine;

use App\Seller\Engine\ToolCatalog;
use PHPUnit\Framework\TestCase;

final class ToolCatalogTest extends TestCase
{
    public function test_nine_prototype_schemas_and_required_arguments_are_preserved_without_effects(): void
    {
        $catalog = ToolCatalog::definitions();
        self::assertSame(['send_price', 'calc_delivery', 'set_followup', 'create_promise', 'escalate', 'mark_do_not_disturb', 'check_stock', 'log_unknown_question', 'send_requisites'], array_keys($catalog));
        self::assertSame(['city'], $catalog['calc_delivery']['function']['parameters']['required']);
        self::assertSame(['datetime'], $catalog['set_followup']['function']['parameters']['required']);
        self::assertSame(['question'], $catalog['log_unknown_question']['function']['parameters']['required']);
        self::assertSame('{}', json_encode($catalog['send_requisites']['function']['parameters']['properties'], JSON_THROW_ON_ERROR));
        self::assertSame('{}', json_encode($catalog['mark_do_not_disturb']['function']['parameters']['properties'], JSON_THROW_ON_ERROR));
        self::assertSame([$catalog['set_followup'], $catalog['send_price']], ToolCatalog::definitionsFor(['set_followup', 'not-a-tool', 'send_price']));
    }
}
