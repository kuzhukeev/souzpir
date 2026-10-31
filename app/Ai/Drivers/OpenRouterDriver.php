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
        $key = config('features.E0-05.defaults.openrouter_key');
        if (! is_string($key) || $key === '') {
            throw new Rejected('AI provider is not configured.');
        }
        $body = $this->wireBody($body);
        $start = hrtime(true);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $response = Http::withToken($key)->acceptJson()->timeout(60)->post('https://openrouter.ai/api/v1/chat/completions', $body);
            } catch (Throwable) {
                throw new Uncertain('Provider outcome is unknown.');
            }
            if ($response->successful()) {
                break;
            }
            // Only an explicit unbilled 429 can be retried. Transport/5xx are uncertain.
            if ($response->status() === 429 && $attempt < 2) {
                usleep(400_000 * (2 ** $attempt));

                continue;
            }
            if (in_array($response->status(), [400, 401, 402, 403, 404, 422, 429], true)) {
                throw new Rejected('Provider rejected request: HTTP '.$response->status());
            }
            throw new Uncertain('Provider outcome is unknown: HTTP '.$response->status());
        }
        try {
            $data = Money::decode($response->body());
            $message = $data['choices'][0]['message'] ?? null;
            if (! is_array($message) || isset($data['error'])) {
                throw new \UnexpectedValueException('Invalid AI response.');
            }
            $calls = [];
            foreach ($message['tool_calls'] ?? [] as $call) {
                if (! is_string($call['id'] ?? null) || ! is_string($call['function']['name'] ?? null) || ! is_string($call['function']['arguments'] ?? null)) {
                    throw new \UnexpectedValueException('Invalid AI tool call.');
                }
                $calls[] = ['id' => $call['id'], 'name' => $call['function']['name'], 'arguments' => json_decode($call['function']['arguments'], true, 512, JSON_THROW_ON_ERROR)];
            }
            $usage = $data['usage'] ?? [];
            $prompt = $this->tokens($usage['prompt_tokens'] ?? null);
            $completion = $this->tokens($usage['completion_tokens'] ?? null);
            $content = $message['content'] ?? null;
            if (! is_string($content) && $content !== null) {
                throw new \UnexpectedValueException('Invalid AI content.');
            }

            return ['content' => $content, 'toolCalls' => $calls, 'usage' => ['promptTokens' => $prompt, 'completionTokens' => $completion], 'model' => (string) ($data['model'] ?? $body['model']), 'latencyMs' => (int) ((hrtime(true) - $start) / 1_000_000), 'costMicros' => isset($usage['cost']) ? Money::micros((string) $usage['cost']) : null];
        } catch (Throwable) {
            throw new Uncertain('Provider responded but usage or result cannot be confirmed.');
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
