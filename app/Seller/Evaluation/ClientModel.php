<?php

namespace App\Seller\Evaluation;

use InvalidArgumentException;

final class ClientModel
{
    public static function options(): array
    {
        $configured = config('features.E7-02N.defaults.client_models', ['fake' => ['label' => 'Тестовая модель', 'strategy' => 'facts']]);

        return collect(is_array($configured) ? $configured : [])->filter(fn ($model, $key) => is_string($key) && is_array($model) && is_string($model['label'] ?? null) && in_array($model['strategy'] ?? null, ['facts', 'behavior'], true))->all();
    }

    public static function strategy(string $key): string
    {
        $model = self::options()[$key] ?? null;
        if ($model === null) {
            throw new InvalidArgumentException('Client model unavailable.');
        }

        return $model['strategy'];
    }
}
