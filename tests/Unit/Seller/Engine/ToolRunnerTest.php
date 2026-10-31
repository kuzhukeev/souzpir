<?php

namespace Tests\Unit\Seller\Engine;

use App\Seller\Engine\ToolCatalog;
use App\Seller\Engine\ToolRunner;
use PHPUnit\Framework\TestCase;

final class ToolRunnerTest extends TestCase
{
    private function config(): array
    {
        return ['rules' => [[
            'city_contains' => ['москва'],
            'price_minor' => ['min' => 10000, 'max' => 20000, 'currency' => 'RUB'],
            'days' => ['min' => 2, 'max' => 3],
        ]]];
    }

    public function test_validated_delivery_uses_only_the_explicit_configuration(): void
    {
        $runner = new ToolRunner;
        $config = $this->config();
        $arguments = ['city' => 'МОСКВА', 'weight_kg' => 2.5];
        $expected = ['status' => 'ready', 'price_minor' => ['min' => 10000, 'max' => 20000, 'currency' => 'RUB'], 'days' => ['min' => 2, 'max' => 3]];
        self::assertSame($expected, $runner->run('calc_delivery', $arguments, $config));
        self::assertSame($expected, $runner->run('calc_delivery', ['city' => 'МОСКВА'], $config));
        self::assertSame(['status' => 'not_configured'], $runner->run('calc_delivery', $arguments, null));
        self::assertSame(['status' => 'not_configured'], $runner->run('calc_delivery', $arguments, ['rules' => []]));
        $damaged = $config;
        $damaged['rules'][0]['price_minor']['max'] = -1;
        self::assertSame(['status' => 'not_configured'], $runner->run('calc_delivery', $arguments, $damaged));
        self::assertSame(['status' => 'no_match'], $runner->run('calc_delivery', ['city' => 'Пермь'], $config));
        self::assertSame(['status' => 'invalid_city'], $runner->run('calc_delivery', ['city' => ''], $config));
        self::assertSame(['city' => 'МОСКВА', 'weight_kg' => 2.5], $arguments);
        self::assertSame($this->config(), $config);
    }

    public function test_invalid_arguments_are_rejected_before_the_calculator(): void
    {
        $runner = new ToolRunner;
        foreach ([
            null, [], ['weight_kg' => 2], ['city' => 3], ['city' => 'Москва', 'weight_kg' => '2'],
            ['city' => 'Москва', 'weight_kg' => INF], ['city' => 'Москва', 'weight_kg' => NAN],
            ['city' => "PRIVATE_CITY\xff"], ['city' => str_repeat('x', 16385)],
            ['city' => 'Москва', 'extra' => 'PRIVATE_EXTRA'],
        ] as $arguments) {
            self::assertSame(['status' => 'invalid_arguments'], $runner->run('calc_delivery', $arguments, $this->config()));
        }
        // Input errors take precedence even if configuration is also absent.
        self::assertSame(['status' => 'invalid_arguments'], $runner->run('calc_delivery', [], null));
    }

    public function test_other_tools_and_unknown_names_are_not_executed_or_inspected(): void
    {
        $runner = new ToolRunner;
        $spy = new class implements \JsonSerializable
        {
            public bool $called = false;

            public function jsonSerialize(): mixed
            {
                $this->called = true;
                throw new \LogicException('PRIVATE_SPY');
            }
        };
        foreach (array_diff(array_keys(ToolCatalog::definitions()), ['calc_delivery']) as $name) {
            self::assertSame(['status' => 'unavailable'], $runner->run($name, $spy, $spy));
        }
        foreach (['unknown', '', 'CALC_DELIVERY', null, [], $spy] as $name) {
            self::assertSame(['status' => 'unavailable'], $runner->run($name, $spy, $spy));
        }
        self::assertSame(['status' => 'invalid_arguments'], $runner->run('calc_delivery', ['city' => $spy], $spy));
        self::assertFalse($spy->called);
    }
}
