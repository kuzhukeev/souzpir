<?php

namespace App\Vision\Reports;

final class SiteCriteria
{
    private const TITLES = [
        1 => 'Доступность содержимого', 2 => 'Обнаружение страниц', 3 => 'Правила доступа роботов', 4 => 'Текст и структурированные данные',
        5 => 'Продукт и его назначение', 6 => 'Параметры и единицы измерения', 7 => 'Категории и варианты', 8 => 'Идентификаторы продуктов',
        9 => 'Цена или порядок расчёта', 10 => 'Наличие и минимальный заказ', 11 => 'Получение товара или услуги', 12 => 'Контакт и путь покупки',
        13 => 'Сведения о компании', 14 => 'Подтверждение заявлений', 15 => 'Согласованность фактов', 16 => 'Актуальность данных',
    ];

    public static function project(mixed $criteria): array
    {
        if (! is_array($criteria)) {
            return [];
        }
        $result = [];
        foreach ($criteria as $criterion) {
            if (! is_array($criterion) || ! is_int($criterion['id'] ?? null) || ! isset(self::TITLES[$criterion['id']])) {
                continue;
            }
            $status = $criterion['status'] ?? null;
            if (! in_array($status, ['pass', 'fail', 'partial', 'unknown'], true)) {
                continue;
            }
            $result[$criterion['id']] = ['title' => self::TITLES[$criterion['id']], 'status' => $status];
        }
        ksort($result);

        return array_slice(array_values($result), 0, 5);
    }
}
