<?php

namespace Tests\Unit\Seller\Settings\Tools;

use App\Seller\Engine\Tools\DeliveryEstimate;
use App\Seller\Settings\Tools\ToolPreferences;
use PHPUnit\Framework\TestCase;

final class ToolSettingsTest extends TestCase
{
    public function test_delivery_ranges_and_unconfigured_city(): void
    {
        $service = new DeliveryEstimate;
        $rules = [['city' => 'Тверь', 'price_min' => 10000, 'price_max' => 20000, 'days_min' => 1, 'days_max' => 3]];
        self::assertSame(['status' => 'ready', 'price_minor' => ['min' => 10000, 'max' => 20000, 'currency' => 'RUB'], 'days' => ['min' => 1, 'max' => 3]], $service->calculate('Тверь', $rules));
        self::assertSame('not_configured', $service->calculate('Москва', [])['status']);
        self::assertContains('escalate', ToolPreferences::REQUIRED);
        self::assertContains('mark_do_not_disturb', ToolPreferences::REQUIRED);
    }
}
