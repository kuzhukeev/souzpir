<?php

namespace App\Ai;

use InvalidArgumentException;
use stdClass;

/** Validate representational empty JSON objects and build a scalar inspection copy. */
final class SchemaObjects
{
    public static function forInspection(#[\SensitiveParameter] array $body): array
    {
        $nodes = 0;

        return self::walk($body, [], 0, $nodes);
    }

    private static function walk(#[\SensitiveParameter] mixed $value, #[\SensitiveParameter] array $path, int $depth, int &$nodes): mixed
    {
        if ($depth > 20 || ++$nodes > 20000) {
            throw new InvalidArgumentException('AI data is too complex to inspect.');
        }
        if (is_array($value)) {
            $copy = [];
            foreach ($value as $key => $child) {
                $copy[$key] = self::walk($child, [...$path, $key], $depth + 1, $nodes);
            }

            return $copy;
        }
        if (is_object($value)) {
            $inResponseSchema = array_slice($path, 0, 3) === ['response_format', 'json_schema', 'schema'];
            $inToolSchema = count($path) >= 4 && $path[0] === 'tools' && is_int($path[1])
                && $path[2] === 'function' && $path[3] === 'parameters';
            if (get_class($value) !== stdClass::class || get_object_vars($value) !== [] || (! $inResponseSchema && ! $inToolSchema)) {
                throw new InvalidArgumentException('AI data contains an unsupported object.');
            }

            // A content-free object has nothing for the privacy inspectors to scan.
            // The original object is retained for encoding and hashing.
            return [];
        }
        if (is_resource($value)) {
            throw new InvalidArgumentException('AI data contains an unsupported value.');
        }

        return $value;
    }
}
