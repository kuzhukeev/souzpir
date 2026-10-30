<?php

declare(strict_types=1);

namespace Souzpir\Baseline;

use App\Agent\Llm\FakeLlmClient;
use App\Agent\Llm\LlmClient;
use App\Agent\Llm\LlmResult;
use RuntimeException;

final class FixtureClient implements LlmClient
{
    private int $sequence = 0;
    private ?FakeLlmClient $fake;

    public function __construct(private string $directory, private string $mode = 'replay')
    {
        if (!in_array($mode, ['replay', 'synthetic'], true)) {
            throw new RuntimeException('Only offline replay and synthetic recording are supported. Live capture belongs to SP-T-03.');
        }
        if ($mode === 'synthetic') {
            if (is_dir($directory) && glob($directory.'/*.json')) {
                throw new RuntimeException('Refusing to overwrite existing evidence.');
            }
            if (!is_dir($directory) && !mkdir($directory, 0775, true)) {
                throw new RuntimeException('Cannot create fixture directory.');
            }
        }
        $this->fake = $mode === 'synthetic' ? new FakeLlmClient : null;
    }

    public function push(array|string $response): void
    {
        if (!$this->fake) {
            throw new RuntimeException('Replay cannot accept replacement responses.');
        }
        $this->fake->push($response);
    }

    public static function redact(mixed $value): mixed
    {
        if (is_object($value)) {
            return (object) self::redact(get_object_vars($value));
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key=>$item) {
                $out[$key] = is_string($key) && preg_match('/^(authorization|api[_-]?key|access[_-]?token|refresh[_-]?token|bot[_-]?token|token|client[_-]?secret|password|secret|cookie|set-cookie)$/i', $key)
                    ? '[REDACTED]' : self::redact($item);
            }
            return $out;
        }
        if (!is_string($value)) {
            return $value;
        }
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [REDACTED]', $value);
        $value = preg_replace('/\bsk-(?:or-v1-)?[A-Za-z0-9_-]{8,}\b/', '[REDACTED_API_KEY]', $value);
        $value = preg_replace('/\b\d{6,12}:[A-Za-z0-9_-]{20,}\b/', '[REDACTED_BOT_TOKEN]', $value);
        $value = preg_replace('/("(?:api[_-]?key|access[_-]?token|refresh[_-]?token|bot[_-]?token|token|client[_-]?secret|password|secret|authorization)"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/i', '$1"[REDACTED]"', $value);
        return preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[EMAIL]', $value);
    }

    public static function canonical(mixed $value): mixed
    {
        if (is_object($value)) {
            $fields = array_map(self::canonical(...), get_object_vars($value));
            ksort($fields, SORT_STRING);
            return (object) $fields;
        }
        if (is_array($value)) {
            $value = array_map(self::canonical(...), $value);
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
        }
        return $value;
    }

    public static function normalizedRequest(array $messages, array $options): array
    {
        // These fields are call bookkeeping, never remove semantic dates inside messages/slots.
        foreach (['timestamp', 'request_id', 'trace_id', 'lead_id', 'message_id'] as $key) {
            unset($options[$key]);
        }
        return self::canonical(self::redact(['messages'=>$messages, 'options'=>$options]));
    }

    public static function json(mixed $value): string
    {
        return json_encode(self::canonical($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)."\n";
    }

    public function chat(array $messages, array $options = []): LlmResult
    {
        $request = self::normalizedRequest($messages, $options);
        $hash = hash('sha256', self::json($request));
        $file = $this->directory.'/'.sprintf('%04d', ++$this->sequence).'.json';
        if ($this->fake) {
            $result = $this->fake->chat($messages, $options);
            $record = ['schema_version'=>1, 'provenance'=>'synthetic', 'provider'=>'donor FakeLlmClient',
                'request_sha256'=>$hash, 'request'=>$request, 'response'=>self::redact(get_object_vars($result))];
            if (file_put_contents($file, self::json($record), LOCK_EX) === false) {
                throw new RuntimeException('Cannot persist redacted synthetic evidence.');
            }
            return $result;
        }
        if (!is_file($file)) {
            throw new RuntimeException('Missing replay fixture at sequence '.$this->sequence.'; no network fallback exists.');
        }
        $encoded = file_get_contents($file);
        $record = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        $storedRequest = json_decode($encoded, false, 512, JSON_THROW_ON_ERROR)->request ?? null;
        if (!in_array($record['provenance'] ?? '', ['synthetic', 'recorded'], true) || ($record['schema_version'] ?? null) !== 1) {
            throw new RuntimeException('Invalid fixture provenance/schema.');
        }
        if (($record['request_sha256'] ?? '') !== $hash || self::json($storedRequest) !== self::json($request)) {
            throw new RuntimeException('Replay request differs from recorded request at sequence '.$this->sequence.'.');
        }
        return new LlmResult(...$record['response']);
    }

    public function assertExhausted(): void
    {
        if (count(glob($this->directory.'/*.json') ?: []) !== $this->sequence) {
            throw new RuntimeException('Replay did not consume every fixture.');
        }
    }
}
