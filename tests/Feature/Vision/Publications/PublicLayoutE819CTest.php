<?php

namespace Tests\Feature\Vision\Publications;

use Tests\TestCase;

final class PublicLayoutE819CTest extends TestCase
{
    public function test_public_layout_handles_unknown_site_and_escapes_published_text(): void
    {
        $html = view('public-report.index', ['expired' => false, 'brandName' => '<script>brand</script>', 'publishedAt' => '2026-11-04', 'report' => [
            'visibility' => null, 'coverage' => null, 'share_of_voice' => null, 'position' => null, 'questions' => 0,
            'site_readiness' => null, 'actions' => ['<script>action</script>'],
        ]])->render();
        self::assertStringContainsString('Оценка сайта в этой публикации недоступна.', $html);
        self::assertStringContainsString('&lt;script&gt;action&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>brand</script>', $html);
    }
}
