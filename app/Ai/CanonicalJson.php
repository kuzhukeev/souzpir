<?php

namespace App\Ai;

final class CanonicalJson
{
    public static function hash(#[\SensitiveParameter] array $data): string
    {
        try {
            return hash('sha256', json_encode(self::normalize($data, 0), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Data cannot be safely encoded.');
        }
    }

    private static function normalize(#[\SensitiveParameter] mixed $value, int $depth): mixed
    {
        // Match json_encode's default depth rather than AI inspection limits:
        // Vision also uses this hash for large, already-authorized reports.
        if ($depth > 512) {
            throw new \InvalidArgumentException('Data is too complex.');
        }
        if (is_object($value)) {
            if (get_class($value) !== \stdClass::class || get_object_vars($value) !== []) {
                throw new \InvalidArgumentException('Unsupported object.');
            }

            return $value;
        }
        if (is_resource($value)) {
            throw new \InvalidArgumentException('Unsupported value.');
        }
        if (! is_array($value)) {
            return $value;
        }
        $copy = [];
        foreach ($value as $key => $child) {
            $copy[$key] = self::normalize($child, $depth + 1);
        }
        if (! array_is_list($value)) {
            ksort($copy, SORT_STRING);
        }

        return $copy;
    }
}
