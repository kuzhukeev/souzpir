<?php

namespace App\Ai\Recovery;

use App\Ai\Money;
use Illuminate\Support\Facades\Http;
use LogicException;
use Throwable;

final class ProviderEvidence
{
    /** @return array{costMicros:int, hash:string} */
    public function forGeneration(string $generationId): array
    {
        $key = config('features.E0-05.defaults.openrouter_key');
        if (! is_string($key) || $key === '' || ! preg_match('/^gen-[A-Za-z0-9_-]{8,150}$/D', $generationId)) {
            throw new LogicException('Provider verification is unavailable.');
        }
        try {
            $response = Http::withToken($key)->acceptJson()->withoutRedirecting()->timeout(15)
                ->get('https://openrouter.ai/api/v1/generation', ['id' => $generationId]);
            if ($response->status() !== 200) {
                throw new LogicException;
            }
            $body = $response->body();
            $data = Money::decode($body)['data'] ?? null;
            if (! is_array($data) || ($data['id'] ?? null) !== $generationId
                || ($data['cancelled'] ?? null) !== false
                || ! in_array($data['finish_reason'] ?? null, ['stop', 'tool_calls', 'length', 'content_filter'], true)
                || ! isset($data['total_cost'])
                || ! is_string($data['total_cost'])) {
                throw new LogicException;
            }

            return ['costMicros' => Money::micros($data['total_cost']), 'hash' => hash('sha256', $body)];
        } catch (Throwable) {
            throw new LogicException('Provider verification is unavailable.');
        }
    }
}
