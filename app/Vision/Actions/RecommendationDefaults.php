<?php

namespace App\Vision\Actions;

final class RecommendationDefaults
{
    /** Приоритет из явного поля рекомендации либо из числа затронутых вопросов (влияние). */
    public function priority(array $item, int $questionCount): string
    {
        $value = is_string($item['priority'] ?? null) ? trim($item['priority']) : '';
        if ($value !== '') {
            return $value;
        }

        return match (true) {
            $questionCount >= 2 => 'Высокая',
            $questionCount === 1 => 'Средняя',
            default => 'Обычная',
        };
    }

    /** Шаги из явного поля рекомендации либо типовой набор по типу действия (есть вопросы / нет). */
    public function steps(array $item, int $questionCount): array
    {
        $steps = array_values(array_filter(
            is_array($item['steps'] ?? null) ? $item['steps'] : [],
            fn ($value) => is_string($value) && trim($value) !== ''
        ));
        if ($steps !== []) {
            return $steps;
        }

        return $questionCount > 0
            ? ['Проверьте описание', 'Уточните контакты', 'Обновите страницу']
            : ['Изучите рекомендацию', 'Назначьте ответственного', 'Отметьте выполнение'];
    }
}
