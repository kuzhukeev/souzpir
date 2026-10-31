<?php

namespace App\Ai;

final class CanonicalJson
{
    public static function hash(#[\SensitiveParameter] array $data): string
    {
        try {
            return hash('sha256', json_encode(self::normalize($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Data cannot be safely encoded.');
        }
    }

    private static function normalize(#[\SensitiveParameter] mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::normalize(...), $value);
    }
}
