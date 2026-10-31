<?php

namespace Tests\Feature\Vision\SiteAudit;

use App\Vision\SiteAudit\CrawlerClient;
use App\Vision\SiteAudit\RobotsPolicy;
use App\Vision\SiteAudit\SiteInspector;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SiteInspectorTest extends TestCase
{
    private function page(string $url, string $body = '', int $status = 200): array
    {
        return ['url' => $url, 'body' => $body, 'status' => $status, 'fetched_at' => '2026-10-04T12:00:00Z'];
    }

    private function inspector(array $pages, array &$seen): SiteInspector
    {
        $crawler = \Mockery::mock(CrawlerClient::class);
        $crawler->shouldReceive('fetch')->andReturnUsing(function ($url) use ($pages, &$seen) {
            $seen[] = $url;
            if (! isset($pages[$url])) {
                throw new \RuntimeException('Unexpected fetch');
            }

            return $pages[$url];
        });

        return new SiteInspector($crawler);
    }

    public function test_robots_are_checked_before_html_and_disallowed_root_is_not_fetched(): void
    {
        $seen = [];
        $url = 'https://example.com';
        $r = $this->inspector([$url.'/robots.txt' => $this->page($url.'/robots.txt', "User-agent: *\nDisallow: /")], $seen)->inspect($url);
        $this->assertSame([$url.'/robots.txt'], $seen);
        $this->assertSame(0, $r['crawled_pages']);
        $this->assertSame(0.0, collect($r['criteria'])->firstWhere('id', 3)['score']);
        $this->assertNull(collect($r['criteria'])->firstWhere('id', 1)['score']);
    }

    public function test_structured_evidence_retains_product_page_url_and_model_tests_stay_pending(): void
    {
        $seen = [];
        $url = 'https://example.com';
        $product = $url.'/catalog/item';
        $json = json_encode(['@type' => 'Product', 'name' => 'Алюминиевый профиль', 'description' => 'Профиль для окон', 'sku' => 'A1', 'url' => $product]);
        $r = $this->inspector([$url.'/robots.txt' => $this->page($url.'/robots.txt', '', 404), $url => $this->page($url, '<a href="/catalog/item">Каталог</a>'.str_repeat('Текст о продукции. ', 30)), $url.'/sitemap.xml' => $this->page($url.'/sitemap.xml', '', 404), $product => $this->page($product, '<script type="application/ld+json">'.$json.'</script>Материал профиля: алюминий.')], $seen)->inspect($url);
        $this->assertSame($url.'/robots.txt', $seen[0]);
        $c = collect($r['criteria'])->firstWhere('id', 8);
        $this->assertSame($product, $c['evidence'][0]['url']);
        foreach ([17, 18, 19, 20] as $id) {
            $c = collect($r['criteria'])->firstWhere('id', $id);
            $this->assertTrue($c['pending']);
            $this->assertNull($c['score']);
        }
    }

    public function test_redirect_destination_policy_is_checked_before_destination_html(): void
    {
        $seen = [];
        $root = 'https://example.com';
        $target = 'https://blocked.example';
        $this->inspector([$root.'/robots.txt' => $this->page($root.'/robots.txt', '', 404), $root => ['location' => $target, ...$this->page($root, '', 302)], $target.'/robots.txt' => $this->page($target.'/robots.txt', "User-agent: *\nDisallow: /")], $seen)->inspect($root);
        $this->assertSame([$root.'/robots.txt', $root, $target.'/robots.txt'], $seen);
    }

    public function test_robots_specific_groups_override_star_and_allow_wins_equal_length(): void
    {
        $robots = $this->page('https://example.com/robots.txt', "User-agent: *\nDisallow: /\nUser-agent: EYON-Audit\nUser-agent: Other\nDisallow: /private*\nAllow: /private/public$\nUser-agent: eyon-audit\nDisallow: /tie\nAllow: /tie");
        $this->assertTrue(RobotsPolicy::allowed('https://example.com/catalog', $robots));
        $this->assertFalse(RobotsPolicy::allowed('https://example.com/private/file', $robots));
        $this->assertTrue(RobotsPolicy::allowed('https://example.com/private/public', $robots));
        $this->assertFalse(RobotsPolicy::allowed('https://example.com/private/public-more', $robots));
        $this->assertTrue(RobotsPolicy::allowed('https://example.com/tie', $robots));
    }

    public function test_unreachable_robots_does_not_produce_zero_quality_or_fetch_html(): void
    {
        $seen = [];
        $r = $this->inspector(['https://example.com/robots.txt' => $this->page('https://example.com/robots.txt', '', 503)], $seen)->inspect('https://example.com');
        $this->assertCount(1, $seen);
        $this->assertEquals(0, $r['readiness']['coverage']);
        $this->assertEquals(100, $r['readiness']['upper']);
        foreach ($r['ai_access']['bots'] as $bot) {
            $this->assertNull($bot['allowed']);
        }
        $this->assertSame(['total' => 12, 'known' => 0, 'unknown' => 12], $r['ai_access']['coverage']);
        $this->assertNull($r['ai_access']['llms_txt']['status']);
        $this->assertNull($r['ai_access']['sitemap']['status']);
        $this->assertArrayNotHasKey('access_index', $r['ai_access']);
    }

    public function test_ai_access_keeps_search_training_and_user_fetch_roles_separate_without_scores(): void
    {
        $url = 'https://example.com';
        $seen = [];
        $robots = "User-agent: *\nAllow: /\nUser-agent: GPTBot\nDisallow: /\nUser-agent: OAI-SearchBot\nAllow: /\nUser-agent: ChatGPT-User\nDisallow: /\nUser-agent: PerplexityBot\nDisallow: /\nUser-agent: ClaudeBot\nDisallow: /\nUser-agent: Claude-User\nDisallow: /\nUser-agent: Google-Extended\nDisallow: /\nUser-agent: YandexBot\nDisallow: /\nUser-agent: Bytespider\nDisallow: /";
        $r = $this->inspector([$url.'/robots.txt' => $this->page($url.'/robots.txt', $robots), $url => $this->page($url, str_repeat('Product details. ', 30)), $url.'/sitemap.xml' => $this->page($url.'/sitemap.xml', '', 404), $url.'/llms.txt' => $this->page($url.'/llms.txt', "# Company\n[Map](https://example.com/sitemap.xml)")], $seen)->inspect($url);
        $bots = collect($r['ai_access']['bots'])->keyBy('user_agent');
        $this->assertTrue($bots['OAI-SearchBot']['allowed']);
        $this->assertSame('search', $bots['OAI-SearchBot']['role']);
        $this->assertFalse($bots['GPTBot']['allowed']);
        $this->assertSame('training', $bots['GPTBot']['role']);
        $this->assertFalse($bots['ChatGPT-User']['allowed']);
        $this->assertSame('user_fetch', $bots['ChatGPT-User']['role']);
        $this->assertFalse($bots['PerplexityBot']['allowed']);
        $this->assertSame('search', $bots['PerplexityBot']['role']);
        $this->assertTrue($bots['Perplexity-User']['allowed']);
        $this->assertSame('user_fetch', $bots['Perplexity-User']['role']);
        $this->assertFalse($bots['ClaudeBot']['allowed']);
        $this->assertSame('training', $bots['ClaudeBot']['role']);
        $this->assertTrue($bots['Claude-SearchBot']['allowed']);
        $this->assertSame('search', $bots['Claude-SearchBot']['role']);
        $this->assertFalse($bots['Claude-User']['allowed']);
        $this->assertSame('user_fetch', $bots['Claude-User']['role']);
        $this->assertTrue($bots['Googlebot']['allowed']);
        $this->assertSame('search', $bots['Googlebot']['role']);
        $this->assertFalse($bots['Google-Extended']['allowed']);
        $this->assertSame('training_grounding', $bots['Google-Extended']['role']);
        $this->assertSame('control_token', $bots['Google-Extended']['kind']);
        $this->assertFalse($bots['YandexBot']['allowed']);
        $this->assertSame('search', $bots['YandexBot']['role']);
        $this->assertFalse($bots['Bytespider']['allowed']);
        $this->assertSame('other', $bots['Bytespider']['role']);
        $this->assertNull($bots['Bytespider']['reference']);
        foreach ($bots as $bot) {
            $this->assertNotEmpty($bot['note']);
            if ($bot['user_agent'] !== 'Bytespider') {
                $this->assertStringStartsWith('https://', $bot['reference']);
            }
        }
        $this->assertSame(['total' => 12, 'known' => 12, 'unknown' => 0], $r['ai_access']['coverage']);
        $this->assertSame($url, $r['ai_access']['checked_url']);
        $this->assertTrue($r['ai_access']['has_sitemap_link']);
        $this->assertSame(200, $r['ai_access']['llms_txt']['status']);
        $this->assertArrayNotHasKey('score_0_100', $r['ai_access']);
        $this->assertArrayNotHasKey('access_index', $r['ai_access']);
        $this->assertSame([$url.'/robots.txt', $url, $url.'/sitemap.xml', $url.'/llms.txt'], $seen);
        $this->assertTrue(RobotsPolicy::allowed($url, $this->page($url.'/robots.txt', "User-agent: NotGPTBot\nDisallow: /"), 'GPTBot'));
    }

    public function test_missing_robots_is_distinct_from_unreachable_robots_and_missing_optional_files_have_no_score(): void
    {
        $url = 'https://example.com';
        foreach ([404, 410] as $status) {
            $seen = [];
            $r = $this->inspector([$url.'/robots.txt' => $this->page($url.'/robots.txt', '', $status), $url => $this->page($url, str_repeat('Product details. ', 30)), $url.'/sitemap.xml' => $this->page($url.'/sitemap.xml', '', 404), $url.'/llms.txt' => $this->page($url.'/llms.txt', '', 404)], $seen)->inspect($url);
            foreach ($r['ai_access']['bots'] as $bot) {
                $this->assertTrue($bot['allowed']);
            }
            $this->assertSame(['total' => 12, 'known' => 12, 'unknown' => 0], $r['ai_access']['coverage']);
            $this->assertSame(404, $r['ai_access']['llms_txt']['status']);
            $this->assertSame(404, $r['ai_access']['sitemap']['status']);
            $this->assertArrayNotHasKey('score_0_100', $r['ai_access']);
            $this->assertArrayNotHasKey('access_index', $r['ai_access']);
            $this->assertCount(4, $seen);
        }
    }

    public function test_yandex_report_rule_uses_specific_group_before_yandex_group_without_changing_crawl_policy(): void
    {
        $url = 'https://example.com';
        $general = "User-agent: *\nAllow: /\nUser-agent: Yandex\nDisallow: /";
        foreach ([$general => false, $general."\nUser-agent: YandexBot\nAllow: /" => true] as $robots => $expected) {
            $seen = [];
            $r = $this->inspector([$url.'/robots.txt' => $this->page($url.'/robots.txt', $robots), $url => $this->page($url, str_repeat('Product details. ', 30)), $url.'/sitemap.xml' => $this->page($url.'/sitemap.xml', '', 404), $url.'/llms.txt' => $this->page($url.'/llms.txt', '', 404)], $seen)->inspect($url);
            $bots = collect($r['ai_access']['bots'])->keyBy('user_agent');
            $this->assertSame($expected, $bots['YandexBot']['allowed']);
            $this->assertTrue($bots['OAI-SearchBot']['allowed']);
            $this->assertSame(1, $r['crawled_pages']);
            $this->assertCount(4, $seen);
            $this->assertSame(1.0, collect($r['criteria'])->firstWhere('id', 3)['score']);
        }
    }

    public function test_plain_html_prices_terms_company_and_messenger_are_evidence_without_json_ld(): void
    {
        $url = 'https://example.com';
        $seen = [];
        $html = '<h1>ИИ-продавец для интернет-магазина</h1><p>Сервис отвечает на вопросы покупателей и помогает оформить заявку.</p>'
            .'<p>Стоимость подключения от 5000 рублей. Запуск за 3 дня. Доступ предоставляется онлайн после оплаты. ИНН 7701234567.</p>'
            .'<a href="/services/seller">Продавец для сайта</a><a href="/services/visibility">Видимость в ИИ</a>'
            .'<a href="https://t.me/company_support">Написать нам</a><a href="/certificate.pdf">Сертификат соответствия</a>';
        $r = $this->inspector([$url.'/robots.txt' => $this->page($url.'/robots.txt', '', 404), $url => $this->page($url, $html),
            $url.'/sitemap.xml' => $this->page($url.'/sitemap.xml', '', 404), $url.'/llms.txt' => $this->page($url.'/llms.txt', '', 404)], $seen)->inspect($url, 1);
        $criteria = collect($r['criteria'])->keyBy('id');
        foreach ([5, 7, 8, 9, 10, 11, 12, 13, 14] as $id) {
            $this->assertSame(.5, $criteria[$id]['score'], 'criterion '.$id);
            $this->assertSame($url, $criteria[$id]['evidence'][0]['url']);
            $this->assertNotEmpty($criteria[$id]['evidence'][0]['excerpt']);
        }
        $this->assertStringContainsString('https://t.me/company_support', $criteria[12]['evidence'][0]['excerpt']);
        $this->assertNull($criteria[15]['score']);
        $this->assertNull($criteria[16]['score']);
        foreach ([17, 18, 19, 20] as $id) {
            $this->assertFalse($criteria[$id]['in_scope']);
            $this->assertNull($criteria[$id]['score']);
        }
    }

    public function test_failed_pages_scripts_and_share_links_do_not_supply_commercial_evidence(): void
    {
        $url = 'https://example.com';
        foreach ([200, 503] as $status) {
            $seen = [];
            $html = '<p>'.str_repeat('Текст общей страницы. ', 12).'</p><a href="https://t.me/share/url?url=https://example.com">Поделиться</a>'
                .'<script>Цена 5000 рублей. ИНН 7701234567. hello@example.com</script>';
            if ($status === 503) {
                $html .= '<p>Стоимость 5000 рублей. ИНН 7701234567. +7 999 123-45-67</p>';
            }
            $r = $this->inspector([$url.'/robots.txt' => $this->page($url.'/robots.txt', '', 404), $url => $this->page($url, $html, $status),
                $url.'/sitemap.xml' => $this->page($url.'/sitemap.xml', '', 404)], $seen)->inspect($url, 1);
            foreach ([9, 12, 13, 14, 15, 16] as $id) {
                $this->assertNull(collect($r['criteria'])->firstWhere('id', $id)['score'], 'criterion '.$id);
            }
        }
    }

    public function test_explicit_examples_do_not_supply_terms_or_schema_but_real_service_terms_survive(): void
    {
        $url = 'https://example.com';
        $example = '<p>Лист 2 мм: цена 5000 рублей, в наличии. Доставка 3–5 дней. ИНН 7701234567.</p>'
            .'<a href="/catalog/sample-a">Образец А</a><a href="/catalog/sample-b">Образец Б</a>'
            .'<script type="application/ld+json">'.json_encode(['@type' => 'Product', 'name' => 'Лист',
                'description' => 'Тестовый товар', 'offers' => ['price' => 5000, 'priceCurrency' => 'RUB',
                    'shippingDetails' => ['deliveryTime' => '3 дня']]]).'</script>';
        foreach ([
            '<section id="demo">'.$example.'</section>',
            '<div aria-label="Пример диалога покупателя с помощником">'.$example.'</div>',
            '<section><h2>Пример расчёта</h2>'.$example.'</section>',
            '<table><caption>Пример таблицы поставщиков</caption><tr><td>'.$example.'</td></tr></table>',
        ] as $exampleBlock) {
            foreach ([false, true] as $realTerms) {
                $seen = [];
                $html = '<h1>Помощник для магазина</h1><p>'.str_repeat('Сервис отвечает на вопросы клиентов. ', 8).'</p>'
                    .$exampleBlock.($realTerms ? '<section><h2>Наши условия</h2><p>Подключение онлайн после оплаты.</p></section>' : '');
                $r = $this->inspector([$url.'/robots.txt' => $this->page($url.'/robots.txt', '', 404),
                    $url => $this->page($url, $html), $url.'/sitemap.xml' => $this->page($url.'/sitemap.xml', '', 404),
                    $url.'/llms.txt' => $this->page($url.'/llms.txt', '', 404)], $seen)->inspect($url, 1);
                $criteria = collect($r['criteria'])->keyBy('id');
                foreach ([6, 7, 8, 9, 10, 13] as $id) {
                    $this->assertNull($criteria[$id]['score'], 'example cannot prove criterion '.$id);
                }
                $this->assertSame($realTerms ? .5 : null, $criteria[11]['score']);
                $this->assertSame('Получение товара или доступ к услуге', $criteria[11]['title']);
                if ($realTerms) {
                    $this->assertStringContainsString('Подключение онлайн после оплаты', $criteria[11]['evidence'][0]['excerpt']);
                    $this->assertStringNotContainsString('Доставка 3–5', $criteria[11]['evidence'][0]['excerpt']);
                }
                $this->assertStringContainsString('Доставка 3–5', $r['pages'][0]['text']);
            }
        }
    }

    public function test_example_heading_does_not_remove_real_terms_in_the_same_wrapper(): void
    {
        $url = 'https://example.com';
        $seen = [];
        $html = '<main><div class="page"><h2>Пример расчёта</h2>'
            .'<p>Доставка образца за 90 дней. ИНН 7701234567.</p>'
            .'<h2>Наши условия</h2><p>Доставка за 2 дня после оплаты.</p></div></main>';
        $r = $this->inspector([$url.'/robots.txt' => $this->page($url.'/robots.txt', '', 404),
            $url => $this->page($url, $html), $url.'/sitemap.xml' => $this->page($url.'/sitemap.xml', '', 404),
            $url.'/llms.txt' => $this->page($url.'/llms.txt', '', 404)], $seen)->inspect($url, 1);
        $criteria = collect($r['criteria'])->keyBy('id');

        $this->assertSame(.5, $criteria[11]['score']);
        $this->assertStringContainsString('Доставка за 2 дня', $criteria[11]['evidence'][0]['excerpt']);
        $this->assertStringNotContainsString('90 дней', $criteria[11]['evidence'][0]['excerpt']);
        $this->assertNull($criteria[13]['score']);
        $this->assertStringContainsString('90 дней', $r['pages'][0]['text']);
    }

    #[DataProvider('fulfilmentBlocks')]
    public function test_fulfilment_requires_a_condition_in_its_own_non_case_block(string $html, ?string $evidence): void
    {
        $url = 'https://example.com';
        $seen = [];
        $result = $this->inspector([
            $url.'/robots.txt' => $this->page($url.'/robots.txt', '', 404),
            $url => $this->page($url, $html),
            $url.'/sitemap.xml' => $this->page($url.'/sitemap.xml', '', 404),
            $url.'/llms.txt' => $this->page($url.'/llms.txt', '', 404),
        ], $seen)->inspect($url, 1);
        $criterion = collect($result['criteria'])->firstWhere('id', 11);

        $this->assertCount(4, $seen);
        $this->assertSame($evidence === null ? null : .5, $criterion['score']);
        $this->assertSame($evidence === null ? 'unknown' : 'partial', $criterion['status']);
        if ($evidence === null) {
            $this->assertSame([], $criterion['evidence']);
            $this->assertStringContainsString('Это не доказывает отсутствие', $criterion['explanation']);
        } else {
            $this->assertSame($url, $criterion['evidence'][0]['url']);
            $this->assertSame('2026-10-04T12:00:00Z', $criterion['evidence'][0]['observed_at']);
            $this->assertStringContainsString($evidence, $criterion['evidence'][0]['excerpt']);
        }
    }

    public static function fulfilmentBlocks(): array
    {
        return [
            'third party results are not access terms' => [
                '<div class="stat"><p>Рост конверсии у сети мастерских после подключения ИИ на нерабочее время '
                    .'(кейс СтороннегоВендора, данные вендора)</p></div>'
                    .'<div class="stat"><strong>92%</strong><p>чатов ИИ закрыл без человека при среднем диалоге 1 мин 50 с.</p></div>',
                null,
            ],
            'explicit case conditions are not our offer' => [
                '<section><h2>Кейс сторонней компании</h2><p>Подключение за 2 дня. Доставка бесплатно.</p></section>',
                null,
            ],
            'case heading in a div also scopes nested content' => [
                '<div><h2>Кейс сторонней компании</h2><article><p>Подключение за 2 дня. Доставка бесплатно.</p></article></div>',
                null,
            ],
            'real conditions following the case heading survive' => [
                '<div><h2>Кейс сторонней компании</h2><p>Подключение за 90 дней.</p>'
                    .'<h2>Наши условия</h2><p>Подключение за 2 дня.</p></div>',
                'Подключение за 2 дня',
            ],
            'marked checklist is not a service offer' => [
                '<section id="checklist"><ul><li>Доставка за 3 дня: укажите эти условия в базе знаний.</li></ul></section>',
                null,
            ],
            'actual checklist wording is not evidence' => [
                '<li>Прайс, условия доставки, оплаты и возврата существуют в текстовом виде и обновлялись за последние 30 дней.</li>',
                null,
            ],
            'nearby performance does not describe connection' => [
                '<p>Подключение ИИ помогает бизнесу: 92% чатов закрыты за 2 минуты.</p>',
                null,
            ],
            'neighboring cards must not join into a condition' => [
                '<div>Подключение</div><div>За 2 дня менеджеры обработали 300 обращений.</div>',
                null,
            ],
            'service activation mode' => ['<p>Подключение онлайн после оплаты.</p>', 'Подключение онлайн после оплаты'],
            'access in passive voice' => ['<p>Доступ предоставляется онлайн после оплаты.</p>', 'Доступ предоставляется онлайн после оплаты'],
            'service activation deadline' => ['<p>Подключаем сервис в течение 2 рабочих дней.</p>', 'Подключаем сервис в течение 2 рабочих дней'],
            'explicit activation duration with a verb' => ['<p>Подключение занимает 2 рабочих дня.</p>', 'Подключение занимает 2 рабочих дня'],
            'physical delivery deadline' => ['<p>Доставка товара за 3–5 дней после оплаты.</p>', 'Доставка товара за 3–5 дней'],
            'courier method and inline markup' => ['<p>Доставка <strong>курьером</strong> до двери.</p>', 'Доставка курьером до двери'],
            'generic checkmark styling does not exclude actual conditions' => ['<ul class="checks"><li>Доставка курьером до двери.</li></ul>', 'Доставка курьером до двери'],
            'collection address' => ['<p>Самовывоз по адресу: улица Лесная, 12.</p>', 'Самовывоз по адресу'],
        ];
    }

    public function test_fulfilment_exclusions_do_not_hide_real_terms_or_change_other_criteria(): void
    {
        $url = 'https://example.com';
        $schema = '<script type="application/ld+json">'.json_encode(['@type' => 'Service', 'name' => 'Проверка сайта',
            'description' => 'Бесплатная проверка сайта', 'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'RUB']]).'</script>';
        $example = '<section class="case-study"><p>После подключения ИИ 92% чатов закрыты за 2 минуты (кейс вендора).</p></section>'
            .'<section id="checklist"><p>Доставка за 3 дня должна быть описана в базе знаний.</p></section>';
        foreach ([false, true] as $realTerms) {
            $seen = [];
            $html = $schema.'<p>Бесплатная проверка за 10 минут.</p>'.$example
                .($realTerms ? '<section><h2>Наши условия</h2><p>Доставка за 2 дня после оплаты.</p></section>' : '');
            $result = $this->inspector([
                $url.'/robots.txt' => $this->page($url.'/robots.txt', '', 404),
                $url => $this->page($url, $html),
                $url.'/sitemap.xml' => $this->page($url.'/sitemap.xml', '', 404),
                $url.'/llms.txt' => $this->page($url.'/llms.txt', '', 404),
            ], $seen)->inspect($url, 1);
            $criteria = collect($result['criteria'])->keyBy('id');

            $this->assertSame(.5, $criteria[6]['score']);
            $this->assertStringContainsString('10 минут', $criteria[6]['evidence'][0]['excerpt']);
            $this->assertSame(.5, $criteria[9]['score']);
            $this->assertStringContainsString('"price":"0"', $criteria[9]['evidence'][0]['excerpt']);
            $this->assertSame($realTerms ? .5 : null, $criteria[11]['score']);
            if ($realTerms) {
                $this->assertStringContainsString('Доставка за 2 дня', $criteria[11]['evidence'][0]['excerpt']);
                $this->assertStringNotContainsString('92%', $criteria[11]['evidence'][0]['excerpt']);
                $this->assertStringNotContainsString('базе знаний', $criteria[11]['evidence'][0]['excerpt']);
            }
            $this->assertStringContainsString('92%', $result['pages'][0]['text']);
            $this->assertStringContainsString('базе знаний', $result['pages'][0]['text']);
        }
    }

    public function test_offer_cards_with_loaded_pages_are_detected_without_catalog_url_conventions(): void
    {
        $url = 'https://example.com';
        $seen = [];
        $html = '<section id="modules"><a href="/assistant/index.html"><h3>Помощник магазина</h3><p>Отвечает клиентам.</p></a>'
            .'<a href="/insights/index.html"><h3>Аналитика обращений</h3><p>Показывает потери клиентов.</p></a></section>';
        $offer = '<h1>Покупатели получат ответ быстрее</h1><p>'.str_repeat('Выберите услугу и оставьте заявку на подключение. ', 6).'</p>';
        $r = $this->inspector([$url.'/robots.txt' => $this->page($url.'/robots.txt', '', 404),
            $url => $this->page($url, $html),
            $url.'/assistant/index.html' => $this->page($url.'/assistant/index.html', $offer),
            $url.'/insights/index.html' => $this->page($url.'/insights/index.html', $offer),
            $url.'/sitemap.xml' => $this->page($url.'/sitemap.xml', '', 404),
            $url.'/llms.txt' => $this->page($url.'/llms.txt', '', 404)], $seen)->inspect($url, 3);
        $criteria = collect($r['criteria'])->keyBy('id');
        $this->assertSame(.5, $criteria[7]['score']);
        $this->assertSame(.5, $criteria[8]['score']);
        $this->assertStringContainsString('/assistant/index.html', $criteria[8]['evidence'][0]['excerpt']);
        $this->assertStringContainsString('/insights/index.html', $criteria[7]['evidence'][0]['excerpt']);
        $this->assertCount(6, $seen);
    }

    public function test_informational_or_failed_pages_are_not_offers_even_with_cards_and_matching_headings(): void
    {
        $url = 'https://example.com';
        $seen = [];
        $html = '<section id="services"><a href="/blog/product-news"><h3>Новинка магазина</h3><p>Свежие события.</p></a>'
            .'<a href="/rules"><h3>Правила сервиса</h3><p>Условия обработки данных.</p></a>'
            .'<a href="/unavailable"><h3>Помощник магазина</h3><p>Отвечает клиентам.</p></a></section>';
        $body = '<p>'.str_repeat('Подробное описание страницы для посетителей. ', 6).'</p>';
        $r = $this->inspector([$url.'/robots.txt' => $this->page($url.'/robots.txt', '', 404),
            $url => $this->page($url, $html),
            $url.'/blog/product-news' => $this->page($url.'/blog/product-news', '<h1>Новинка магазина</h1>'.$body),
            $url.'/rules' => $this->page($url.'/rules', '<h1>Политика обработки персональных данных</h1>'.$body),
            $url.'/unavailable' => $this->page($url.'/unavailable', '<h1>Помощник магазина</h1>'.$body, 503),
            $url.'/sitemap.xml' => $this->page($url.'/sitemap.xml', '', 404),
            $url.'/llms.txt' => $this->page($url.'/llms.txt', '', 404)], $seen)->inspect($url, 4);
        $criteria = collect($r['criteria'])->keyBy('id');
        $this->assertNull($criteria[7]['score']);
        $this->assertNull($criteria[8]['score']);
    }

    public function test_offer_identity_can_be_confirmed_by_loaded_heading_or_software_schema(): void
    {
        $url = 'https://example.com';
        $seen = [];
        $html = '<a href="/assistant">Помощник магазина</a><a href="/insights">Аналитика обращений</a>';
        $schema = '<script type="application/ld+json">'.json_encode(['@type' => 'SoftwareApplication',
            'name' => 'Аналитика обращений', 'description' => 'Приложение показывает ответы и потерянные обращения.']).'</script>';
        $r = $this->inspector([$url.'/robots.txt' => $this->page($url.'/robots.txt', '', 404),
            $url => $this->page($url, $html),
            $url.'/assistant' => $this->page($url.'/assistant', '<h1>Помощник магазина для покупателей</h1><p>'.str_repeat('Сервис отвечает на вопросы клиентов. ', 6).'</p>'),
            $url.'/insights' => $this->page($url.'/insights', $schema),
            $url.'/sitemap.xml' => $this->page($url.'/sitemap.xml', '', 404),
            $url.'/llms.txt' => $this->page($url.'/llms.txt', '', 404)], $seen)->inspect($url, 3);
        $criteria = collect($r['criteria'])->keyBy('id');
        $this->assertSame(.5, $criteria[7]['score']);
        $this->assertSame(.5, $criteria[8]['score']);
        $this->assertStringContainsString('/insights', $criteria[8]['evidence'][0]['excerpt']);
    }

    public function test_consistency_requires_same_product_fact_on_distinct_pages_and_dates_are_explicit(): void
    {
        $url = 'https://example.com';
        foreach ([5000, 6000] as $otherPrice) {
            $seen = [];
            $product = fn ($price): string => '<script type="application/ld+json">'.json_encode([
                '@type' => 'Product', 'name' => 'Профиль', 'description' => 'Профиль для рам',
                'sku' => 'ABC-123', 'offers' => ['price' => $price, 'priceCurrency' => 'RUB'], 'dateModified' => '2026-01-01',
            ]).'</script>';
            $r = $this->inspector([$url.'/robots.txt' => $this->page($url.'/robots.txt', '', 404),
                $url => $this->page($url, $product(5000).'<a href="/catalog/profile">Профиль</a>'),
                $url.'/catalog/profile' => $this->page($url.'/catalog/profile', $product($otherPrice)),
                $url.'/sitemap.xml' => $this->page($url.'/sitemap.xml', '', 404)], $seen)->inspect($url, 2);
            $criteria = collect($r['criteria'])->keyBy('id');
            $this->assertSame($otherPrice === 5000 ? .5 : null, $criteria[15]['score']);
            if ($otherPrice === 5000) {
                $this->assertCount(2, $criteria[15]['evidence']);
            }
            $this->assertSame(.5, $criteria[16]['score']);
            $this->assertStringContainsString('заявленная дата', $criteria[16]['explanation']);
        }
    }

    public function test_copyright_year_does_not_confirm_freshness(): void
    {
        $url = 'https://example.com';
        $seen = [];
        $r = $this->inspector([$url.'/robots.txt' => $this->page($url.'/robots.txt', '', 404),
            $url => $this->page($url, '<footer>© '.now()->year.' Company. Все права защищены.</footer>'),
            $url.'/sitemap.xml' => $this->page($url.'/sitemap.xml', '', 404)], $seen)->inspect($url, 1);
        $this->assertNull(collect($r['criteria'])->firstWhere('id', 16)['score']);
    }

    #[DataProvider('modifiedDates')]
    public function test_modified_date_requires_a_real_calendar_date_in_an_unambiguous_format(string $modifiedDate, ?float $expectedScore): void
    {
        $url = 'https://example.com';
        $seen = [];
        $html = '<script type="application/ld+json">'.json_encode(['@type' => 'WebPage', 'dateModified' => $modifiedDate]).'</script>';
        $r = $this->inspector([
            $url.'/robots.txt' => $this->page($url.'/robots.txt', '', 404),
            $url => $this->page($url, $html),
            $url.'/sitemap.xml' => $this->page($url.'/sitemap.xml', '', 404),
            $url.'/llms.txt' => $this->page($url.'/llms.txt', '', 404),
        ], $seen)->inspect($url, 1);

        $criterion = collect($r['criteria'])->firstWhere('id', 16);
        $this->assertSame($expectedScore, $criterion['score']);
        if ($expectedScore === null) {
            $this->assertSame([], $criterion['evidence']);
        } else {
            $this->assertStringContainsString('29.02.2024', $criterion['explanation']);
            $this->assertStringContainsString('заявленная дата', $criterion['explanation']);
            $this->assertStringContainsString($modifiedDate, $criterion['evidence'][0]['excerpt']);
        }
    }

    public static function modifiedDates(): array
    {
        return [
            'impossible day must not roll into March' => ['2026-02-31', null],
            'non leap year' => ['2025-02-29T12:30:00Z', null],
            'invalid month' => ['2024-13-01', null],
            'zero day' => ['2024-02-00', null],
            'relative time expression' => ['2024-02-29T+1 day', null],
            'ambiguous non ISO date' => ['29.02.2024', null],
            'invalid hour must not roll into next day' => ['2024-02-29T25:00:00Z', null],
            'valid leap day' => ['2024-02-29', .5],
            'valid ISO timestamp' => ['2024-02-29T12:30:00Z', .5],
        ];
    }
}
