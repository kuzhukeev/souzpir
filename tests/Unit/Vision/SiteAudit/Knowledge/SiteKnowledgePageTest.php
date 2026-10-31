<?php

namespace Tests\Unit\Vision\SiteAudit\Knowledge;

use App\Vision\SiteAudit\Knowledge\SiteKnowledgePage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SiteKnowledgePageTest extends TestCase
{
    public function test_navigation_is_same_origin_html_only_and_index_alias_is_canonical(): void
    {
        $pages = new SiteKnowledgePage;
        $html = file_get_contents(dirname(__DIR__, 4).'/Feature/Vision/SiteAudit/Knowledge/Fixtures/home.html');
        $page = $pages->extract($html, 'https://brand.example/');
        self::assertSame(['https://brand.example/details', 'https://brand.example/index.html'], $page['links']);
        self::assertSame('https://brand.example/', $pages->canonicalUrl('https://brand.example/index.html#top'));
        self::assertSame('https://brand.example/', $pages->canonicalUrl('https://brand.example/index.htm'));
        self::assertSame('policy', $pages->kind('Продукты > Ограничения'));
        self::assertSame('catalog', $pages->kind('Продукты > Цены'));
        self::assertSame('process', $pages->kind('Как подключить'));
        self::assertSame('identity', $pages->kind('Контакты'));
    }

    public function test_hidden_template_and_navigation_headings_cannot_become_the_document_title(): void
    {
        $pages = new SiteKnowledgePage;
        foreach (['<h1 hidden>SECRET_HIDDEN</h1>', '<template><h1>SECRET_TEMPLATE</h1></template>', '<nav><h1>SECRET_NAV</h1></nav>'] as $hidden) {
            $page = $pages->extract('<html><head><title>Fallback</title></head><body>'.$hidden.'<h1>Visible product</h1><p>Visible information.</p></body></html>', 'https://brand.example/');
            self::assertSame('Visible product', $page['title']);
            self::assertStringNotContainsString('SECRET_', $page['text']);
        }
        $page = $pages->extract('<html><head><title>Fallback</title></head><body><h1 hidden>SECRET_HIDDEN</h1><p>Visible information.</p></body></html>', 'https://brand.example/');
        self::assertSame('Fallback', $page['title']);
    }

    public function test_unsafe_url_forms_and_invalid_html_are_rejected(): void
    {
        $pages = new SiteKnowledgePage;
        foreach (['file:///etc/passwd', 'http://127.0.0.1/', 'https://name:pass@brand.example/', 'http://internal.local/', 'https://brand.example:8443/'] as $url) {
            try {
                $pages->url($url);
                self::fail('Unsafe URL accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        foreach (['<p>short</p>', str_repeat('x', 600001), "<p>bad \xff UTF-8</p>"] as $html) {
            try {
                $pages->extract($html, 'https://brand.example/');
                self::fail('Invalid content accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
