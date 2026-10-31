<?php

namespace App\Privacy\Secrets;

use InvalidArgumentException;
use JsonException;

/** JSON inspection without losing duplicate object members. */
final class JsonObjectInspector
{
    public function decode(#[\SensitiveParameter] string $json): array
    {
        try {
            $result = json_decode($json, true, 22, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException;
        }
        if (! is_array($result)) {
            throw new InvalidArgumentException;
        }

        // The input was validated above. Scan every original string token before
        // inspecting the decoded value, since json_decode collapses duplicates.
        $stack = [];
        $length = strlen($json);
        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];
            if ($char === '{' || $char === '[') {
                $stack[] = ['object' => $char === '{', 'keys' => []];
            } elseif ($char === '}' || $char === ']') {
                array_pop($stack);
            } elseif ($char === '"') {
                $start = $i;
                while (++$i < $length) {
                    if ($json[$i] === '\\') {
                        $i++;
                    } elseif ($json[$i] === '"') {
                        break;
                    }
                }
                $next = $i + 1;
                while ($next < $length && str_contains(" \t\r\n", $json[$next])) {
                    $next++;
                }
                $top = count($stack) - 1;
                if ($top >= 0 && $stack[$top]['object'] && $next < $length && $json[$next] === ':') {
                    try {
                        $key = json_decode(substr($json, $start, $i - $start + 1), true, 2, JSON_THROW_ON_ERROR);
                    } catch (JsonException) {
                        throw new InvalidArgumentException;
                    }
                    if (! is_string($key) || array_key_exists($key, $stack[$top]['keys'])) {
                        throw new InvalidArgumentException;
                    }
                    $stack[$top]['keys'][$key] = true;
                }
            }
        }

        return $result;
    }
}
