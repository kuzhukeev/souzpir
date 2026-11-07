<?php

namespace Tests\Feature\Vision\Profile;

use App\Vision\Profile\AuditedFulfilmentHints;
use PHPUnit\Framework\TestCase;

final class AuditedFulfilmentE814HTest extends TestCase
{
    public function test_only_successful_same_host_evidence_is_suggested(): void
    {
        $audit = ['pages' => [
            ['url' => 'https://example.com/delivery', 'status' => 200, 'fulfilment_evidence' => 'Доставка в течение двух дней'],
            ['url' => 'https://example.com/second', 'status' => 200, 'fulfilment_evidence' => 'Доставка в течение двух дней'],
            ['url' => 'https://other.example/delivery', 'status' => 200, 'fulfilment_evidence' => 'Чужая доставка'],
            ['url' => 'https://example.com/fail', 'status' => 500, 'fulfilment_evidence' => 'Ошибка страницы'],
            ['url' => 'https://example.com/empty', 'status' => 200, 'fulfilment_evidence' => ' '],
        ]];
        $reader = new AuditedFulfilmentHints;
        $hints = $reader->extract($audit, 'https://example.com');
        self::assertCount(1, $hints);
        self::assertSame('Доставка в течение двух дней', $hints[0]['value']);
        self::assertSame('https://example.com/delivery', $hints[0]['source']);
        self::assertSame([], $reader->extract($audit, 'не адрес'));
        self::assertSame([], $reader->extract(['pages' => []], 'https://example.com'));
    }
}
