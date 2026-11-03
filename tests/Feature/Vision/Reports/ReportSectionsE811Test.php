<?php

namespace Tests\Feature\Vision\Reports;

use App\Ai\CanonicalJson;
use App\Vision\Reports\ReportData;
use App\Vision\Reports\ReportSections;
use Tests\TestCase;

final class ReportSectionsE811Test extends TestCase
{
    public function test_reviewed_facts_and_recommendations_only_use_snapshot_data(): void
    {
        $sections = app(ReportSections::class)->make([
            'reviewed_facts' => [['text' => 'Проверенное', 'confirmed' => true, 'provider' => 'gemini'], ['text' => 'Не проверено', 'confirmed' => false]],
            'recommendations' => ['items' => [['title' => 'Обновить карточку', 'why' => 'Данные снимка']]],
        ]);
        self::assertSame('Проверенное', $sections['facts'][0]['text']);
        self::assertCount(1, $sections['facts']);
        self::assertSame('Обновить карточку', $sections['actions'][0]['title']);
        self::assertSame([], app(ReportSections::class)->make([])['actions']);
    }

    public function test_site_is_taken_only_from_an_intact_measurement_snapshot(): void
    {
        $profile = ['site' => 'https://example.test'];
        $measurement = (object) ['profile_snapshot' => json_encode($profile), 'profile_checksum' => CanonicalJson::hash($profile)];
        $reader = app(ReportData::class);
        self::assertSame('https://example.test', $reader->siteFromProfile($measurement));
        $measurement->profile_checksum = str_repeat('0', 64);
        self::assertNull($reader->siteFromProfile($measurement));
        self::assertNull($reader->siteFromProfile(null));
    }
}
