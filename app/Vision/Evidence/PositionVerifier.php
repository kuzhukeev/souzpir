<?php

namespace App\Vision\Evidence;

/** Positions are known only when every line of a numbered list is represented. */
final class PositionVerifier
{
    public function complete(#[\SensitiveParameter] string $text, #[\SensitiveParameter] array $companies): bool
    {
        $lines = preg_split('/\R/u', trim($text));
        if (! is_array($lines) || $lines === [] || count($lines) !== count($companies)) {
            return false;
        }
        foreach ($lines as $index => $line) {
            if (! is_array($companies[$index] ?? null) || ! is_string($companies[$index]['name'] ?? null)) {
                return false;
            }
            $rawName = $companies[$index]['name'];
            if (preg_match('/(?:[,;\/&]|(?<!\p{L})(?:и|или|and|or)(?!\p{L}))/iu', $rawName)) {
                return false;
            }
            $name = preg_quote($rawName, '/');
            if (! preg_match('/^\s*'.($index + 1).'[.)]\s+'.$name.'\s*$/iu', $line)) {
                return false;
            }
        }

        return true;
    }
}
