<?php

namespace Tests\Unit\Seller\Engine;

use App\Seller\Engine\PrototypeDeliveryEstimate;
use PHPUnit\Framework\TestCase;

final class PrototypeDeliveryEstimateTest extends TestCase
{
    public function test_every_captured_prototype_case_matches_without_executing_effects(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/fixtures/delivery-prototype-synthetic.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('laravel-prototype/app/Agent/Tools/ToolRunner.php::calcDelivery', $fixture['source']);
        self::assertSame('e29606398a1da572d4e6bbb6cf27c7a9ae755a618f4da7219dfa151e73909e64', $fixture['source_sha256']);
        self::assertCount(96, $fixture['cases']);

        $estimate = new PrototypeDeliveryEstimate;
        foreach ($fixture['cases'] as $index => $case) {
            self::assertSame($case['prototype'], $estimate->calculate($case['arguments']['city']), 'Prototype case '.$index);
        }
    }

    public function test_matching_order_unknown_city_and_original_spelling_are_preserved(): void
    {
        $estimate = new PrototypeDeliveryEstimate;
        $result = $estimate->calculate('  МОСКВА, Екатеринбург  ')['result'];
        self::assertSame('  МОСКВА, Екатеринбург  ', $result['city']);
        self::assertSame('бесплатно до терминала ТК / самовывоз', $result['cost_range']);
        // The donor checks "нижн" before "нижневартовск".
        self::assertSame('2 500–6 000 руб.', $estimate->calculate('Нижневартовск')['result']['cost_range']);
        self::assertSame('нужен расчёт на сайте ТК', $estimate->calculate('')['result']['cost_range']);
        self::assertSame('3–14', $estimate->calculate('Неизвестный город')['result']['days']);
    }
}
