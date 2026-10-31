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

    public function test_explicitly_hidden_subtrees_do_not_provide_titles_text_or_links(): void
    {
        $pages = new SiteKnowledgePage;
        $html = <<<'HTML'
<html><body>
<section hidden><h1>HIDDEN_TITLE</h1><p>HIDDEN_TEXT_1</p><a href="/hidden-1">Hidden</a></section>
<div aria-hidden="TrUe"><div><a href="/hidden-2">HIDDEN_TEXT_2</a></div></div>
<div style="color:red; DISPLAY : none !important"><a href="/hidden-3">HIDDEN_TEXT_3</a></div>
<div style="visibility: /* comment */ hidden"><div><a href="/hidden-4">HIDDEN_TEXT_4</a></div></div>
<h1>Visible product</h1><p>Visible content and price 999 рублей.</p>
<a href="/visible" style="display: block; visibility: visible">Visible link</a>
<p style='background:url("x;display:none;fake");color:red'>Visible quoted value.</p>
</body></html>
HTML;
        $page = $pages->extract($html, 'https://brand.example/');
        self::assertSame('Visible product', $page['title']);
        self::assertStringNotContainsString('HIDDEN_', $page['text']);
        self::assertStringContainsString('999 рублей', $page['text']);
        self::assertStringContainsString('Visible quoted value', $page['text']);
        self::assertSame(['https://brand.example/visible'], $page['links']);
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
