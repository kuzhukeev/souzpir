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
            || ! hash_equals(CanonicalJson::hash(self::comparable($record['request'])), CanonicalJson::hash(self::comparable($body)))
            || ! is_array($record['response'] ?? null)) {
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
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];

        return ['content' => $response['content'], 'toolCalls' => $response['toolCalls'] ?? [],
            'usage' => ['promptTokens' => (int) ($usage['prompt_tokens'] ?? 0), 'completionTokens' => (int) ($usage['completion_tokens'] ?? 0)],
            'model' => $response['model'], 'latencyMs' => (int) ($response['latencyMs'] ?? 0),
            'costMicros' => $record['evidence']['budget']['charged_micros'] ?? null];
    }

    /**
     * Recorded calls nest provider options under `options` (donor harness shape); the live
     * runtime sends a flat AiRequest::toArray(). Both encode the same request; reshape the
     * flat body to the nested form before hashing so the comparison is on substance, not on
     * which of the two equivalent JSON shapes was used. `component` is the donor harness's own
     * bookkeeping label — it is never part of the wire request, so it cannot appear in $body and
     * is dropped from both sides; which stage a call belongs to is already fixed by the cursor
     * over the case's own ordered llm_calls. `enum`/`required` arrays inside a JSON schema are
     * unordered sets by definition; sort them so a harmless member reordering (e.g. LeadState
     * slot order) does not register as a request mismatch.
     */
    private static function comparable(array $request): array
    {
        if (array_key_exists('options', $request)) {
            $messages = $request['messages'] ?? null;
            $options = is_array($request['options']) ? $request['options'] : [];
        } else {
            $messages = $request['messages'] ?? null;
            $options = [
                'model' => $request['model'] ?? null,
                'temperature' => $request['temperature'] ?? null,
                'response_format' => $request['response_format'] ?? null,
                'tools' => $request['tools'] ?? null,
                'tool_choice' => $request['tool_choice'] ?? null,
                'max_tokens' => $request['max_tokens'] ?? null,
            ];
        }
        unset($options['component']);
        $options = array_filter($options, fn ($value) => $value !== null);

        return ['messages' => $messages, 'options' => self::sortUnorderedSchemaLists($options)];
    }

    private static function sortUnorderedSchemaLists(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $copy = [];
        foreach ($value as $key => $child) {
            $child = self::sortUnorderedSchemaLists($child);
            if (in_array($key, ['enum', 'required'], true) && array_is_list($child)
                && array_reduce($child, fn ($safe, $item) => $safe && is_string($item), true)) {
                sort($child, SORT_STRING);
            }
            $copy[$key] = $child;
        }

        return $copy;
    }
}
