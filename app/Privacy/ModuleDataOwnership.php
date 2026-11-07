<?php

namespace App\Privacy;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/** Единый источник владельцев данных модулей бренда и гарантии хранения после отключения (SP-E1-04I). */
final class ModuleDataOwnership
{
    public const RETENTION_DAYS = 90;

    private const OWNERS = [
        'seller' => 'Продавец (App\\Seller): диалоги, база знаний, каналы и настройки бренда.',
        'vision' => 'Видение (App\\Vision): анкета, замеры видимости и отчёты бренда.',
    ];

    public static function owner(string $module): string
    {
        return self::OWNERS[$module] ?? throw new InvalidArgumentException('Unknown module.');
    }

    public static function retentionReviewAt(DateTimeInterface $disabledAt): CarbonImmutable
    {
        return CarbonImmutable::instance($disabledAt)->addDays(self::RETENTION_DAYS);
    }
}
