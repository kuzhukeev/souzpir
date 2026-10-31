<?php

namespace Tests\Unit\Seller\Engine;

use App\Seller\Engine\ConfiguredDeliveryEstimate;
use PHPUnit\Framework\TestCase;

final class ConfiguredDeliveryEstimateTest extends TestCase
{
    private function config(): array
    {
        return ['rules' => [
            ['city_contains' => ['москва', 'общая'], 'price_minor' => ['min' => 120000, 'max' => 230000, 'currency' => 'RUB'], 'days' => ['min' => 2, 'max' => 4]],
            ['city_contains' => ['екатеринбург', 'общая'], 'price_minor' => ['min' => 240000, 'max' => 550000, 'currency' => 'RUB'], 'days' => ['min' => 5, 'max' => 8]],
        ]];
    }

    public function test_explicit_rules_return_only_the_selected_range_in_order_without_mutation(): void
    {
        $calculator = new ConfiguredDeliveryEstimate;
        $config = $this->config();
        $before = $config;
        self::assertSame([
            'status' => 'ready', 'price_minor' => ['min' => 120000, 'max' => 230000, 'currency' => 'RUB'], 'days' => ['min' => 2, 'max' => 4],
        ], $calculator->calculate('  МОСКВА, Екатеринбург  ', $config));
        self::assertSame('ready', $calculator->calculate('ОбЩаЯ область', $config)['status']);
        self::assertSame(120000, $calculator->calculate('ОбЩаЯ область', $config)['price_minor']['min']);
        self::assertSame(240000, $calculator->calculate('Екатеринбург', $config)['price_minor']['min']);
        self::assertSame(['status' => 'no_match'], $calculator->calculate('Неизвестный город', $config));
        self::assertSame($before, $config);
        self::assertSame(['status' => 'not_configured'], $calculator->calculate('Москва', null));
    }

    public function test_absent_and_damaged_config_never_returns_a_price_even_if_the_first_rule_matches(): void
    {
        $valid = $this->config();
        $bad = [null, [], ['rules' => []], ['rules' => 'invalid'], ['rules' => [1]], ['rules' => [0 => $valid['rules'][0], 2 => $valid['rules'][1]]], ['rules' => $valid['rules'], 'unknown' => true]];
        foreach ([
            ['city_contains', []], ['city_contains', ['']], ['city_contains', ["\xff"]], ['city_contains', ['Москва', 7]],
            ['price_minor', ['min' => -1, 'max' => 2, 'currency' => 'RUB']],
            ['price_minor', ['min' => 3, 'max' => 2, 'currency' => 'RUB']],
            ['price_minor', ['min' => '1', 'max' => 2, 'currency' => 'RUB']],
            ['price_minor', ['min' => 0, 'max' => INF, 'currency' => 'RUB']],
            ['price_minor', ['min' => 0, 'max' => 1, 'currency' => 'rub']],
            ['days', ['min' => -1, 'max' => 1]], ['days', ['min' => 2, 'max' => 1]],
            ['days', ['min' => 1, 'max' => 2, 'extra' => true]],
        ] as [$key, $value]) {
            $damaged = $valid;
            $damaged['rules'][1][$key] = $value;
            $bad[] = $damaged;
        }
        $missing = $valid;
        unset($missing['rules'][1]['days']);
        $bad[] = $missing;
        $calculator = new ConfiguredDeliveryEstimate;
        foreach ($bad as $index => $config) {
            self::assertSame(['status' => 'not_configured'], $calculator->calculate('Москва', $config), 'Invalid case '.$index);
        }
    }

    public function test_bad_city_is_not_interpreted_as_an_estimate_and_input_is_not_echoed(): void
    {
        $calculator = new ConfiguredDeliveryEstimate;
        foreach (['', ' ', "\xff PRIVATE_CITY", str_repeat('x', 1025), null, 12] as $city) {
            self::assertSame(['status' => 'invalid_city'], $calculator->calculate($city, $this->config()));
        }
    }

    public function test_result_does_not_share_mutable_references_with_configuration(): void
    {
        $config = $this->config();
        $minimum = 120000;
        $day = 2;
        $config['rules'][0]['price_minor']['min'] = &$minimum;
        $config['rules'][0]['days']['min'] = &$day;
        $result = (new ConfiguredDeliveryEstimate)->calculate('Москва', $config);
        $result['price_minor']['min'] = 1;
        $result['days']['min'] = 1;
        self::assertSame(120000, $minimum);
        self::assertSame(2, $day);
    }

    public function test_zero_is_allowed_only_when_explicit_and_city_terms_are_literal(): void
    {
        $config = ['rules' => [[
            'city_contains' => ['город.*'],
            'price_minor' => ['min' => 0, 'max' => 0, 'currency' => 'RUB'],
            'days' => ['min' => 0, 'max' => 0],
        ]]];
        $calculator = new ConfiguredDeliveryEstimate;
        self::assertSame(['status' => 'no_match'], $calculator->calculate('город пример', $config));
        self::assertSame(['status' => 'ready', 'price_minor' => ['min' => 0, 'max' => 0, 'currency' => 'RUB'], 'days' => ['min' => 0, 'max' => 0]], $calculator->calculate('город.*', $config));
    }
}
