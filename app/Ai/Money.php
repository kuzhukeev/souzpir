<?php

namespace App\Ai;

use InvalidArgumentException;

/** Converts an exact nonnegative decimal USD amount to integer micros, rounding up. */
final class Money
{
    public static function micros(string $number): int
    {
        if (! preg_match('/^(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d{1,3}))?$/D', $number, $parts)) {
            throw new InvalidArgumentException('Invalid provider cost.');
        }
        $fraction = $parts[2] ?? '';
        $digits = ltrim($parts[1].$fraction, '0');
        if ($digits === '') {
            return 0;
        }
        $shift = 6 + (int) ($parts[3] ?? 0) - strlen($fraction);
        if ($shift >= 0) {
            if (strlen($digits) + $shift > 18) {
                throw new InvalidArgumentException('Provider cost exceeds supported range.');
            }

            return (int) ($digits.str_repeat('0', $shift));
        }
        $cut = strlen($digits) + $shift;
        $whole = $cut > 0 ? substr($digits, 0, $cut) : '0';
        $remainder = $cut > 0 ? substr($digits, $cut) : $digits;
        if (strlen($whole) > 18) {
            throw new InvalidArgumentException('Provider cost exceeds supported range.');
        }

        return (int) $whole + (trim($remainder, '0') !== '' ? 1 : 0);
    }

    /** Decode JSON numeric lexemes as strings so billing never goes through binary floats. */
    public static function decode(string $json): array
    {
        $out = '';
        $quoted = false;
        $escaped = false;
        $length = strlen($json);
        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];
            if ($quoted) {
                $out .= $char;
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $quoted = false;
                }
            } elseif ($char === '"') {
                $quoted = true;
                $out .= $char;
            } elseif (($char >= '0' && $char <= '9') || $char === '-') {
                if (! preg_match('/\G-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?/', $json, $match, 0, $i)) {
                    throw new InvalidArgumentException('Malformed provider JSON number.');
                }
                $out .= '"'.$match[0].'"';
                $i += strlen($match[0]) - 1;
            } else {
                $out .= $char;
            }
        }
        $value = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($value)) {
            throw new InvalidArgumentException('Invalid provider JSON.');
        }

        return $value;
    }
}
