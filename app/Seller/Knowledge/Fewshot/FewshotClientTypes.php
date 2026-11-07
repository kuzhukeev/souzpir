<?php

namespace App\Seller\Knowledge\Fewshot;

final class FewshotClientTypes
{
    /** Client types the sales engine assigns; must match App\Seller\Engine\Policy::clientType(). */
    public const ALL = ['all', 'newbie', 'experienced', 'for_self', 'prices_only', 'unknown'];

    public const LABELS = [
        'all' => 'Любой тип клиента',
        'newbie' => 'Новичок',
        'experienced' => 'Опытный',
        'for_self' => 'Для себя',
        'prices_only' => 'Интересует только цена',
        'unknown' => 'Пока не определён',
    ];
}
