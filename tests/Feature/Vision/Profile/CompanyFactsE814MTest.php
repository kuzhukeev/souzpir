<?php

namespace Tests\Feature\Vision\Profile;

use App\Vision\Profile\AuditedCompanyFacts;
use PHPUnit\Framework\TestCase;

final class CompanyFactsE814MTest extends TestCase
{
    public function test_explicit_company_fact_headings_are_limited_to_successful_same_site_pages(): void
    {
        $audit = ['pages' => [
            ['url' => 'https://example.test/about', 'status' => 200, 'headings' => ['Адрес: Москва', 'Телефон: +7 123', 'Год основания: 2010', 'Сертификаты: ISO 9001', 'Доставка: по России', 'Описание без значения']],
            ['url' => 'https://other.test/', 'status' => 200, 'headings' => ['Адрес: Чужой']],
            ['url' => 'https://example.test/error', 'status' => 500, 'headings' => ['Телефон: Ошибка']],
        ]];
        $facts = (new AuditedCompanyFacts)->extract($audit, 'https://example.test');
        self::assertSame(['Москва', '+7 123', '2010', 'ISO 9001', 'по России'], array_column($facts, 'value'));
        self::assertSame('Данные сайта: Сертификаты', $facts[3]['label']);
        self::assertSame('https://example.test/about', $facts[0]['source']);
        self::assertSame([], (new AuditedCompanyFacts)->extract($audit, 'not a URL'));
    }
}
