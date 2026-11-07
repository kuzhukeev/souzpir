<?php

namespace App\Seller\Knowledge\Facts;

/** Offline example only; it performs no provider or network call. */
final class FactExampleFakeDriver
{
    public function generate(string $fact): array
    {
        return ['question' => 'Расскажите об этом подробнее.', 'answer' => $fact];
    }
}
