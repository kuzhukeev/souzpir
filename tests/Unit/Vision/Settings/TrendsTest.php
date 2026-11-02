<?php

namespace Tests\Unit\Vision\Settings;

use App\Vision\Settings\Trends;
use Tests\TestCase;

final class TrendsTest extends TestCase
{
    public function test_projection_preserves_metrics_and_unknown_values(): void
    {
        $row = app(Trends::class)->project(['metrics' => [
            'visibility' => ['macro_percent' => 42],
            'panel' => ['share_of_voice' => null],
            'providers' => ['model' => ['mention_rate' => 30, 'position_average' => 2]],
            'intents' => ['choose' => ['mention_rate' => 20]],
            'entities' => [['name' => 'Конкурент', 'share_of_voice' => 15]],
        ]], 'report', '2026-11-02');
        $this->assertSame(42, $row['visibility']);
        $this->assertNull($row['share']);
        $this->assertSame(30, $row['providers']['model']['visibility']);
        $this->assertSame(20, $row['intents']['choose']);
        $this->assertSame(15, $row['entities']['Конкурент']);
    }
}
