<?php

declare(strict_types=1);

namespace Souzpir\Baseline;

/** Case-local aliases, applied before transport and before the donor consumes results. */
final class ContactNormalizer
{
    private array $contacts = [];
    private array $issued = [];
    private ?array $frozenDateFact = null;

    public function assertOriginal(mixed $value): void
    {
        if (str_contains(strtolower(FixtureClient::json($value)), '@baseline.invalid')) {
            throw new \RuntimeException('Reserved contact namespace present in original source.');
        }
    }

    public function normalize(mixed $value, bool $recorded = false, ?string $key = null): mixed
    {
        return $this->normalizeValue($value, $recorded, $key);
    }

    /** Phone exceptions are internal byte spans, never caller-supplied numbers. */
    private function normalizeValue(mixed $value, bool $recorded, ?string $key, array $phoneSpans = []): mixed
    {
        if ($key !== null && preg_match('/^(authorization|api[-_]key|access[-_]token|refresh[-_]token|bot[-_]token|token|client[-_]secret|password|secret|cookie|set-cookie)$/i', $key)) {
            return '[REDACTED]';
        }
        if (is_object($value)) {
            $out = new \stdClass;
            foreach (get_object_vars($value) as $k => $v) {
                $out->{$k} = $this->normalize($v, $recorded, (string) $k);
            }
            return $out;
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->normalize($v, $recorded, (string) $k);
            }
            return $value;
        }
        if (!is_string($value)) {
            return $value;
        }
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $trimmed = trim($value);
        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            try {
                $json = json_decode($trimmed, false, 512, JSON_THROW_ON_ERROR);
                if (is_object($json) || is_array($json)) {
                    return json_encode($this->normalize($json, $recorded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
                }
            } catch (\JsonException) {
            }
        }
        if (!in_array($key, ['id', 'provider_request_id', 'model', 'component'], true)) {
            preg_match_all('/(?<![\\p{L}\\p{N}_])\\+?\\d(?:[ ().-]*\\d){9,14}(?![\\p{L}\\p{N}_])/u', $value, $phones, PREG_OFFSET_CAPTURE);
            foreach ($phones[0] as $phone) {
                if (!in_array($phone, $phoneSpans, true)) {
                    throw new \RuntimeException('Possible personal phone number requires review; no evidence written.');
                }
            }
        }
        $value = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer [REDACTED]', $value);
        $value = preg_replace('/\bsk-[A-Za-z0-9_-]{10,}\b/', '[REDACTED]', $value);
        $value = preg_replace('/\b\d{6,}:[A-Za-z0-9_-]{20,}\b/', '[REDACTED]', $value);
        // These wrappers are also legal RFC email local-part characters. Recognize
        // only a complete, previously issued alias before the general email token;
        // never trim arbitrary punctuation from an unknown reserved address.
        $pattern = <<<'REGEX'
/
    (?<![\p{L}\p{N}.!#$%&'*+\/=?^_\x60{|}~@\\-])
    (?<wrapper>\*\*|\x60|')
    (?<alias>contact-[0-9]{4}@baseline\.invalid)
    \k<wrapper>
    (?![\p{L}\p{N}!#$%&'*+\/=?^_\x60{|}~@-]|\.[\p{L}\p{N}])
    |
    [A-Z0-9.!#$%&'*+\/=?^_\x60{|}~-]+@[A-Z0-9](?:[A-Z0-9.-]*[A-Z0-9])?\.[A-Z]{2,}
/iux
REGEX;
        return preg_replace_callback($pattern, function (array $m) use ($recorded): string {
            if (($m['alias'] ?? '') !== '') {
                if (!isset($this->issued[$m['alias']])) {
                    throw new \RuntimeException('Unknown or colliding reserved contact alias.');
                }
                return $m[0];
            }
            $email = strtolower($m[0]);
            if (str_contains($email, '@baseline.invalid')) {
                if (!str_ends_with($email, '@baseline.invalid')) {
                    throw new \RuntimeException('Unknown or colliding reserved contact alias.');
                }
                if (isset($this->issued[$email])) {
                    return $email;
                }
                $expected = sprintf('contact-%04d@baseline.invalid', count($this->issued) + 1);
                if (!$recorded || $email !== $expected) {
                    throw new \RuntimeException('Unknown or colliding reserved contact alias.');
                }
                $this->issued[$email] = true;
                return $email;
            }
            if (!isset($this->contacts[$email])) {
                $alias = sprintf('contact-%04d@baseline.invalid', count($this->issued) + 1);
                $this->contacts[$email] = $alias;
                $this->issued[$alias] = true;
            }
            return $this->contacts[$email];
        }, $value);
    }

    /** Exact donor-derived fact, pinned independently of input or provider text. */
    private function frozenDateFact(): array
    {
        if ($this->frozenDateFact !== null) {
            return $this->frozenDateFact;
        }
        $root = dirname(__DIR__, 2).'/.local/prototype';
        $pins = [
            'resources/knowledge/conditions.yaml'=>'495f2f2fd78a866861b66b6cb05178375e7dc74eafbc9a4a82410b1997ebc39b',
            'resources/knowledge/facts.yaml'=>'b8eaa8dbe80dd330f300751764ab57f0d1e23b75cde7fa71e861f1295eb8e75d',
            'app/Agent/Knowledge.php'=>'79c00ceddd114865508714ab0da9e3ae1eecc2a69ae171096df8d359a3b82ee2',
            'resources/views/prompts/responder.blade.php'=>'f15f2a2b660e31e9bf49d3492b75a89757005e16053d42eaf70c508239da4e09',
        ];
        foreach ($pins as $path=>$sha) {
            if (!is_file($root.'/'.$path) || hash_file('sha256', $root.'/'.$path) !== $sha) {
                throw new \RuntimeException('Frozen date fact source differs.');
            }
        }
        $reflection = new \ReflectionClass(\App\Agent\Knowledge::class);
        if (realpath($reflection->getFileName()) !== realpath($root.'/app/Agent/Knowledge.php')) {
            throw new \RuntimeException('Frozen knowledge implementation differs.');
        }
        $knowledge = new \App\Agent\Knowledge($root.'/resources/knowledge', 0);
        $until = $knowledge->conditions()['promotions'][0]['until'] ?? null;
        $fact = $knowledge->fact('too_early_response');
        if (!is_int($until) || !is_array($fact) || count($fact['facts'] ?? []) !== 3
            || substr_count($fact['facts'][0], (string) $until) !== 1) {
            throw new \RuntimeException('Frozen parsed date fact differs.');
        }
        $escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $line = '- ['.$escape($fact['id']).'] '.$escape(implode('; ', $fact['facts']))
            .' (как подвести к вопросу: '.$escape($fact['bridge_hint']).')';
        return $this->frozenDateFact = ['timestamp'=>(string) $until, 'line'=>$line];
    }

    private function sourceDateSpans(string $content): array
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        // Ordinary requests do not load knowledge merely to normalize contacts.
        if (!preg_match('/(?<![\\p{L}\\p{N}_])\\+?\\d(?:[ ().-]*\\d){9,14}(?![\\p{L}\\p{N}_])/u', $content)) {
            return [];
        }
        $source = $this->frozenDateFact();
        $header = "ФАКТЫ, НА КОТОРЫЕ МОЖНО ОПИРАТЬСЯ (пересказать своими словами, не копировать)\n";
        $line = "\n".$source['line']."\n";
        if (substr_count($content, $header) !== 1 || substr_count($content, $line) !== 1) {
            return [];
        }
        $lineOffset = strpos($content, $line);
        if ($lineOffset < strpos($content, $header) + strlen($header) - 1) {
            return [];
        }
        $numberOffset = strpos($line, $source['timestamp']);
        if ($numberOffset === false) {
            throw new \RuntimeException('Frozen date occurrence differs.');
        }
        return [[$source['timestamp'], $lineOffset + $numberOffset]];
    }

    public function request(array $messages, array $options): array
    {
        foreach (['timestamp', 'request_id', 'trace_id', 'lead_id', 'message_id'] as $volatile) {
            unset($options[$volatile]);
        }
        foreach ($messages as $index=>$message) {
            if ($index === 0 && is_array($message) && ($message['role'] ?? null) === 'system'
                && is_string($message['content'] ?? null) && ($options['component'] ?? null) === 'responder') {
                $content = $message['content'];
                unset($message['content']);
                $message = $this->normalize($message);
                $message['content'] = $this->normalizeValue($content, false, 'content', $this->sourceDateSpans($content));
                $messages[$index] = $message;
            } else {
                $messages[$index] = $this->normalize($message);
            }
        }
        return FixtureClient::canonical(['messages'=>$messages, 'options'=>$this->normalize($options)]);
    }
}
