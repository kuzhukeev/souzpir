<?php

namespace App\Ai\Drivers;

use App\Ai\Money;
use App\Ai\SchemaObjects;
use Illuminate\Support\Facades\Http;
use Throwable;

final class OpenRouterDriver implements Driver
{
    public function complete(#[\SensitiveParameter] array $body): array
    {
        return $this->completeInternal($body, false);
    }

    /** One provider request; raw arguments are data, not action authority. */
    public function completeWithRawToolCalls(#[\SensitiveParameter] array $body): array
    {
        return $this->completeInternal($body, true);
    }

    private function completeInternal(#[\SensitiveParameter] array $body, bool $captureRaw): array
    {
        $key = config('features.E0-05.defaults.openrouter_key');
        if (! is_string($key) || $key === '') {
            throw new Rejected('AI provider is not configured.');
        }
        $body = $this->wireBody($body);
        $start = hrtime(true);
        try {
            // A redirect can repeat a POST (307/308); no second transport is
            // justified without a verified provider idempotency contract.
            $response = Http::withToken($key)->acceptJson()->withoutRedirecting()->timeout(60)
                ->post('https://openrouter.ai/api/v1/chat/completions', $body);
        } catch (Throwable) {
            throw new Uncertain('Provider outcome is unknown.');
        }
        if ($response->status() !== 200) {
            // A status alone does not prove an unbilled call; partial or merely
            // accepted responses (including 206/202) are also indeterminate.
            throw new Uncertain('Provider outcome is unknown.');
        }
        $generationId = null;
        try {
            $envelope = json_decode($response->body(), true);
            $candidateId = is_array($envelope) ? ($envelope['id'] ?? null) : null;
            if (is_string($candidateId) && preg_match('/^gen-[A-Za-z0-9_-]{8,150}$/D', $candidateId)) {
                $generationId = $candidateId;
            }
            if ($captureRaw) {
                app(ProtectedRawToolCalls::class)->decodeEnvelope($response->body());
            }
            $data = Money::decode($response->body());
            $message = $data['choices'][0]['message'] ?? null;
            if (! is_array($message) || isset($data['error'])) {
                throw new \UnexpectedValueException('Invalid AI response.');
            }
            // Only explicitly complete text and tool-call outcomes are handled.
            // A length limit, missing or unknown finish reason must never release
            // the reservation merely because a partial response has a cost.
            $finishReason = $data['choices'][0]['finish_reason'] ?? null;
            if (! in_array($finishReason, ['stop', 'tool_calls'], true)) {
                throw new \UnexpectedValueException('AI response completion is unconfirmed.');
            }
            // A syntactically valid but incomplete JSON object is not a
            // confirmed empty response. Tool-only messages are still valid.
            $toolCalls = $message['tool_calls'] ?? [];
            if (! is_array($toolCalls) || ! array_is_list($toolCalls)
                || (! array_key_exists('content', $message) && $toolCalls === [])) {
                throw new \UnexpectedValueException('Invalid AI response.');
            }
            if (($finishReason === 'stop' && ($toolCalls !== [] || ! is_string($message['content'] ?? null)))
                || ($finishReason === 'tool_calls' && $toolCalls === [])) {
                throw new \UnexpectedValueException('AI response completion does not match its message.');
            }
            $calls = [];
            $rawCalls = [];
            if ($captureRaw) {
                $protected = app(ProtectedRawToolCalls::class)->parse($toolCalls, true);
                $calls = $protected['normalized'];
                $rawCalls = $protected['raw'];
            } else {
                foreach ($toolCalls as $call) {
                    if (! is_string($call['id'] ?? null) || ! is_string($call['function']['name'] ?? null) || ! is_string($call['function']['arguments'] ?? null)) {
                        throw new \UnexpectedValueException('Invalid AI tool call.');
                    }
                    $calls[] = ['id' => $call['id'], 'name' => $call['function']['name'], 'arguments' => json_decode($call['function']['arguments'], true, 512, JSON_THROW_ON_ERROR)];
                }
            }
            $usage = $data['usage'] ?? [];
            $prompt = $this->tokens($usage['prompt_tokens'] ?? null);
            $completion = $this->tokens($usage['completion_tokens'] ?? null);
            $content = $message['content'] ?? null;
            if (! is_string($content) && $content !== null) {
                throw new \UnexpectedValueException('Invalid AI content.');
            }

            $result = ['content' => $content, 'toolCalls' => $calls, 'usage' => ['promptTokens' => $prompt, 'completionTokens' => $completion], 'model' => (string) ($data['model'] ?? $body['model']), 'latencyMs' => (int) ((hrtime(true) - $start) / 1_000_000), 'costMicros' => isset($usage['cost']) ? Money::micros((string) $usage['cost']) : null, 'providerGenerationId' => $generationId];

            return $captureRaw ? ['result' => $result, 'raw_tool_calls' => $rawCalls] : $result;
        } catch (Throwable) {
            throw new Uncertain('Provider responded but usage or result cannot be confirmed.', $generationId);
        }
    }

    /** The same payload is measured for budget admission and sent over HTTP. */
    public function wireBody(#[\SensitiveParameter] array $body): array
    {
        try {
            SchemaObjects::forInspection($body);

            return $this->wireBodyInternal($body);
        } catch (Throwable) {
            throw new \InvalidArgumentException('AI payload cannot be safely prepared.');
        }
    }

    private function wireBodyInternal(#[\SensitiveParameter] array $body): array
    {
        unset($body['prompt_version'], $body['prompt_checksum']);
        $body['usage'] = ['include' => true];
        $body['reasoning'] = ['effort' => 'low'];
        $body['max_tokens'] ??= 700;
        if (! empty($body['tools'])) {
            $body['tool_choice'] ??= 'auto';
        }
        if (($body['response_format']['type'] ?? null) === 'json_schema') {
            if (str_starts_with($body['model'], 'anthropic/')) {
                $schema = $body['response_format']['json_schema']['schema'] ?? null;
                $body['messages'][] = ['role' => 'user', 'content' => 'Ответь строго одним JSON-объектом без пояснений и без ```'.($schema ? ' по схеме: '.json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : '')];
                unset($body['response_format']);
            } else {
                $body['provider'] = ['require_parameters' => true];
            }
        }

        return $body;
    }

    private function tokens(mixed $value): int
    {
        if (! is_string($value) || ! ctype_digit($value) || strlen($value) > 9) {
            throw new \UnexpectedValueException('Invalid provider token usage.');
        }

        return (int) $value;
    }
}
