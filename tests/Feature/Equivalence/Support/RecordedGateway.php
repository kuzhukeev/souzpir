<?php

namespace Tests\Feature\Equivalence\Support;

use App\Ai\CanonicalJson;
use App\Ai\Drivers\Driver;
use App\Ai\Drivers\Rejected;
use JsonException;

/** Offline adapter for one recorded case; it never falls back to a live provider. */
final class RecordedGateway implements Driver
{
    private int $cursor = 0;

    public function __construct(private readonly string $root, private readonly array $case)
    {
        if (! is_string($case['case_id'] ?? null) || ! is_array($case['llm_calls'] ?? null)) {
            throw new Rejected('Invalid recorded case.');
        }
    }

    public function complete(#[\SensitiveParameter] array $body): array
    {
        $entry = $this->case['llm_calls'][$this->cursor] ?? null;
        $path = is_array($entry) ? ($entry['path'] ?? null) : null;
        if (! is_string($path) || ! preg_match('~\Allm/[a-zA-Z0-9._-]+/[0-9]{4}\.json\z~D', $path)) {
            throw new Rejected('Recorded call unavailable.');
        }
        $file = $this->root.'/'.$path;
        if (! is_file($file) || ! hash_equals((string) ($entry['sha256'] ?? ''), hash_file('sha256', $file))) {
            throw new Rejected('Recorded call failed integrity check.');
        }
        try {
            $record = json_decode(file_get_contents($file), true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new Rejected('Recorded call is invalid.');
        }
        if (($record['evidence']['case_id'] ?? null) !== $this->case['case_id'] || ! is_array($record['request'] ?? null)
            || ! hash_equals(CanonicalJson::hash($record['request']), CanonicalJson::hash($body)) || ! is_array($record['response'] ?? null)) {
            throw new Rejected('Recorded request does not match.');
        }
        $response = $record['response'];
        $content = $response['content'] ?? null;
        $toolCalls = $response['toolCalls'] ?? [];
        if (($response['error'] ?? null) !== null || (! is_string($content) && $content !== null)
            || ! is_array($toolCalls) || ($content === null && $toolCalls === []) || ! is_string($response['model'] ?? null)) {
            throw new Rejected('Recorded provider returned an error.');
        }
        $this->cursor++;

        return ['content' => $response['content'], 'toolCalls' => $response['toolCalls'] ?? [], 'usage' => $response['usage'] ?? [],
            'model' => $response['model'], 'latencyMs' => $response['latencyMs'] ?? 0, 'costMicros' => $record['evidence']['budget']['charged_micros'] ?? null];
    }
}
