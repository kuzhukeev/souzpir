<?php

namespace Tests\Feature\Vision\Competitors;

use App\Vision\Settings\Trends;
use PHPUnit\Framework\TestCase;

final class TrendsE820PTest extends TestCase
{
    public function test_projection_shows_at_most_five_tracked_competitors_and_excludes_brand(): void
    {
        $entities = [];
        foreach (range(1, 7) as $i) {
            $entities[] = ['name' => 'Компания '.$i, 'share_of_voice' => $i, 'is_brand' => $i === 1];
        }
        $tracked = array_map(fn ($i) => 'Компания '.$i, range(1, 6));
        $row = (new Trends)->project(['metrics' => ['entities' => $entities]], 'report', '2026-11-05', $tracked);
        self::assertSame(['Компания 2' => 2, 'Компания 3' => 3, 'Компания 4' => 4, 'Компания 5' => 5], $row['entities']);
        self::assertSame([], (new Trends)->project(['metrics' => ['entities' => $entities]], 'report', '2026-11-05')['entities']);
    }
}
