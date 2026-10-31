<?php

namespace App\Privacy;

use InvalidArgumentException;
use Normalizer;
use Throwable;

/**
 * Last, fail-closed inspection of a request after legacy contact masking.
 *
 * This boundary never mutates the payload: normalization/decoding is used only
 * for inspection. Call it before reserving a call or invoking any driver.
 */
final class ContactBoundary
{
    private const MAX_DEPTH = 24;

    private const MAX_NODES = 100_000;

    private const MAX_STRING_BYTES = 4_194_304;

    private const MAX_INPUT_BYTES = 16_777_216;

    private const MAX_ROUNDS = 8;

    public function assertSafe(#[\SensitiveParameter] array $request): void
    {
        if (! isset($request['messages']) || ! is_array($request['messages']) || $request['messages'] === [] || ! array_is_list($request['messages'])) {
            throw new InvalidArgumentException('Cannot inspect malformed AI messages.');
        }
        $budget = 0;
        $nodes = 0;
        $this->walk($request, 0, $budget, $nodes);

        // A phone can be split across separate content blocks or messages.
        // Inspect the concatenation as well; the unchanged body is still sent.
        $chunks = [];
        foreach ($request['messages'] ?? [] as $message) {
            if (! is_array($message)) {
                throw new InvalidArgumentException('Cannot inspect malformed AI messages.');
            }
            if (array_key_exists('content', $message)) {
                $this->collectValues($message['content'], $chunks, 0);
            }
            $calls = $message['tool_calls'] ?? [];
            if (! is_array($calls) || ! array_is_list($calls)) {
                throw new InvalidArgumentException('Cannot inspect malformed AI tool calls.');
            }
            foreach ($calls as $tool) {
                if (! is_array($tool)) {
                    throw new InvalidArgumentException('Cannot inspect malformed AI tool calls.');
                }
                if (isset($tool['function']['arguments'])) {
                    $this->collectValues($tool['function']['arguments'], $chunks, 0);
                }
            }
        }
        if ($chunks !== []) {
            $this->inspectString(implode("\n", $chunks), $budget);
        }
    }

    private function walk(#[\SensitiveParameter] mixed $value, int $depth, int &$budget, int &$nodes): void
    {
        if ($depth > self::MAX_DEPTH || ++$nodes > self::MAX_NODES) {
            throw new InvalidArgumentException('AI data is too complex to inspect.');
        }
        if (is_array($value)) {
            $hasPhoneKey = false;
            foreach ($value as $key => $child) {
                if (is_string($key)) {
                    $this->inspectString($key, $budget);
                    $hasPhoneKey = $hasPhoneKey || preg_match('/(?:^|[_-])(?:phone|tel|mobile|телефон|контакт)(?:$|[_0-9-])/iu', $key) === 1;
                }
                if (is_int($key) && abs($key) >= 1_000_000_000) {
                    throw new InvalidArgumentException('AI data contains an ambiguous numeric key.');
                }
                $this->walk($child, $depth + 1, $budget, $nodes);
            }
            if ($hasPhoneKey) {
                $values = [];
                $this->collectValues($value, $values, $depth + 1);
                $numberParts = [];
                foreach ($values as $part) {
                    $probe = $this->inspectionForm($part);
                    if (preg_match('/^[+\p{Nd}\p{Z}\p{Pd}\p{Cf}\s().\/_*#,|;:]+$/u', $probe) === 1) {
                        $numberParts[] = $probe;
                    }
                }
                if ($numberParts !== []) {
                    $this->inspectString('phone '.implode("\n", $numberParts), $budget);
                }
            }

            return;
        }
        if (is_string($value)) {
            $this->inspectString($value, $budget);
            // Tool arguments and message content may themselves be JSON strings.
            $trimmed = trim($value);
            if (($trimmed[0] ?? '') === '{' || ($trimmed[0] ?? '') === '[') {
                $decoded = json_decode($trimmed, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $this->walk($decoded, $depth + 1, $budget, $nodes);
                    $chunks = [];
                    $this->collectValues($decoded, $chunks, $depth + 1);
                    if ($chunks !== []) {
                        $this->inspectString(implode("\n", $chunks), $budget);
                    }
                }
            }

            return;
        }
        if (is_int($value) || is_float($value)) {
            if (is_float($value) && ! is_finite($value)) {
                throw new InvalidArgumentException('AI numeric data is not finite.');
            }
            // Large numeric scalars may be phone numbers in structured input.
            if (abs($value) >= 1_000_000_000) {
                throw new InvalidArgumentException('AI data contains an ambiguous long number.');
            }

            return;
        }
        if ($value !== null && ! is_bool($value)) {
            throw new InvalidArgumentException('AI data must contain only arrays and scalar values.');
        }
    }

    private function collectValues(#[\SensitiveParameter] mixed $value, #[\SensitiveParameter] array &$chunks, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('AI data nesting is too deep to inspect.');
        }
        if (is_array($value)) {
            foreach ($value as $child) {
                $this->collectValues($child, $chunks, $depth + 1);
            }
        } elseif (is_string($value) || is_int($value) || is_float($value)) {
            $chunks[] = (string) $value;
        }
    }

    private function inspectString(#[\SensitiveParameter] string $value, int &$budget): void
    {
        $length = strlen($value);
        $budget += $length;
        if ($length > self::MAX_STRING_BYTES || $budget > self::MAX_INPUT_BYTES || ! mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException('AI text cannot be safely inspected.');
        }
        $text = $this->inspectionForm($value);
        if (preg_match('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}]/u', $text) !== 0) {
            throw new InvalidArgumentException('AI text contains unsafe control characters.');
        }

        // Inspect a copy without formatting controls and HTML tags too. The
        // original request is never rewritten by this boundary.
        $plain = preg_replace('/\p{Cf}+/u', '', $text);
        if ($plain === null) {
            throw new InvalidArgumentException('AI text cannot be safely inspected.');
        }
        foreach (array_unique([$text, $plain, strip_tags($plain)]) as $probe) {
            $this->inspectContacts($probe);
        }
    }

    private function inspectContacts(#[\SensitiveParameter] string $text): void
    {
        // Remaining @ is ambiguous: it can be an email, a handle or an
        // unrecognised encoding. Legacy-masked ordinary emails contain no @.
        $bracketed = preg_match('/[\p{L}\p{N}._%+\-]+\s*[\[(]\s*(?:at|собака)\s*[\])]\s*[\p{L}\p{N}]/iu', $text);
        $worded = preg_match('/[\p{L}\p{N}._%+\-]+\s+(?:at|собака)\s+[\p{L}\p{N}-]+(?:(?:\s*[.\x{3002}]\s*|\s+(?:dot|точка)\s+|\s*[\[(]\s*(?:dot|точка|[.\x{3002}])\s*[\])]\s*)[\p{L}\p{N}-]+)+/iu', $text);
        if (str_contains($text, '@') || $bracketed !== 0 || $worded !== 0) {
            throw new InvalidArgumentException('AI text contains an unresolved contact.');
        }

        // Contact-like sequences in uncommon Unicode/encoded forms must not
        // fall through simply because the earlier phone masker missed them.
        $match = preg_match('/(?<![\p{L}\p{N}_])\p{Nd}(?:[\p{Z}\p{Pd}\p{Cf}\s().\/_+*#,|]{0,6}\p{Nd}){9,}(?![\p{L}\p{N}_])/u', $text);
        $labelled = preg_match('/(?:тел(?:ефон)?|tel(?:ephone)?|phone|mobile|мобильн(?:ый|ого)?|контакт|звоните|позвоните|call(?:\s+me)?|whatsapp|telegram)[\s_:=.+\-]{0,8}\p{Nd}(?:[\p{Z}\p{Pd}\p{Cf}\s().\/_+*#,|;:]{0,6}\p{Nd}){4,}/iu', $text);
        // Colons and semicolons occur in schedules and price lists. Treat
        // them as separators when the groups have a recognisable phone shape;
        // the label-aware check above also covers other explicitly named phones.
        $grouped = preg_match('/(?<![\p{L}\p{N}_])\+?\p{Nd}{1,2}[;:\p{Z}\p{Pd}\s().\/_+*#,|]{1,6}\p{Nd}{3}[;:\p{Z}\p{Pd}\s().\/_+*#,|]{1,6}\p{Nd}{3}[;:\p{Z}\p{Pd}\s().\/_+*#,|]{1,6}\p{Nd}{2}[;:\p{Z}\p{Pd}\s().\/_+*#,|]{1,6}\p{Nd}{2}(?![\p{L}\p{N}_])/u', $text);
        if ($match === false || $match === 1 || $labelled === false || $labelled === 1 || $grouped === false || $grouped === 1) {
            throw new InvalidArgumentException('AI text contains an ambiguous phone-like number.');
        }
    }

    private function inspectionForm(#[\SensitiveParameter] string $value): string
    {
        if (! class_exists(Normalizer::class)) {
            throw new InvalidArgumentException('Unicode normalization is unavailable.');
        }
        $text = $value;
        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            if (! mb_check_encoding($text, 'UTF-8')) {
                throw new InvalidArgumentException('AI text is not valid UTF-8.');
            }
            $normalized = Normalizer::normalize($text, Normalizer::FORM_KC);
            if (! is_string($normalized)) {
                throw new InvalidArgumentException('AI text cannot be normalized.');
            }
            $decoded = html_entity_decode($normalized, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
            $decoded = rawurldecode($decoded);
            $decoded = $this->decodeEscapes($decoded);
            if (strlen($decoded) > self::MAX_STRING_BYTES || ! mb_check_encoding($decoded, 'UTF-8')) {
                throw new InvalidArgumentException('Decoded AI text cannot be safely inspected.');
            }
            if ($decoded === $text) {
                return $decoded;
            }
            $text = $decoded;
        }

        throw new InvalidArgumentException('AI text encoding is too deeply nested.');
    }

    private function decodeEscapes(#[\SensitiveParameter] string $value): string
    {
        try {
            // Combine surrogate pairs first, so compatibility characters
            // outside the BMP are also considered by the NFKC inspection.
            $value = preg_replace_callback(
                '/(?:\\\\)+u([dD][89aAbB][0-9a-fA-F]{2})(?:\\\\)+u([dD][c-fC-F][0-9a-fA-F]{2})/',
                static fn (array $m): string => json_decode('"\\u'.$m[1].'\\u'.$m[2].'"', flags: JSON_THROW_ON_ERROR),
                $value,
            );
            if ($value === null) {
                throw new InvalidArgumentException('Cannot inspect escaped AI text.');
            }
            $value = preg_replace_callback(
                '/(?:\\\\)+u([0-9a-fA-F]{4})/',
                static fn (array $m): string => json_decode('"\\u'.$m[1].'"', flags: JSON_THROW_ON_ERROR),
                $value,
            );
            if ($value === null) {
                throw new InvalidArgumentException('Cannot inspect escaped AI text.');
            }
            $value = preg_replace_callback(
                '/(?:\\\\)+U([0-9a-fA-F]{8})/',
                static function (array $m): string {
                    $codepoint = hexdec($m[1]);
                    if ($codepoint > 0x10FFFF || ($codepoint >= 0xD800 && $codepoint <= 0xDFFF)) {
                        throw new InvalidArgumentException('Invalid Unicode code point.');
                    }

                    return mb_chr($codepoint, 'UTF-8') ?: throw new InvalidArgumentException('Invalid Unicode code point.');
                },
                $value,
            );
            if ($value === null) {
                throw new InvalidArgumentException('Cannot inspect escaped AI text.');
            }
            $value = preg_replace_callback(
                '/(?:\\\\)+x([0-9a-fA-F]{2})/',
                static fn (array $m): string => chr(hexdec($m[1])),
                $value,
            );
            if ($value === null) {
                throw new InvalidArgumentException('Cannot inspect escaped AI text.');
            }

            $value = preg_replace_callback(
                '/(?:\\\\)+([0-7]{3})/',
                static function (array $m): string {
                    $codepoint = octdec($m[1]);
                    if ($codepoint > 255) {
                        throw new InvalidArgumentException('Invalid octal escape.');
                    }

                    return chr($codepoint);
                },
                $value,
            );
            if ($value === null) {
                throw new InvalidArgumentException('Cannot inspect escaped AI text.');
            }

            return $value;
        } catch (Throwable) {
            throw new InvalidArgumentException('AI text contains an invalid escape.');
        }
    }
}
