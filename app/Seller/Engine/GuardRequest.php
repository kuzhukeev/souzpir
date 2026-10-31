<?php

namespace App\Seller\Engine;

use App\Contracts\Ai\AiRequest;
use InvalidArgumentException;
use SensitiveParameter;
use Throwable;

/** Pure request construction only; it does not verify a turn or authorize an AI call. */
final class GuardRequest
{
    private const MAX_INPUT_BYTES = 262144;

    public function build(
        #[SensitiveParameter] string $renderedPrompt,
        #[SensitiveParameter] string $reply,
        #[SensitiveParameter] string $model,
        #[SensitiveParameter] string $promptVersion,
        #[SensitiveParameter] string $promptChecksum,
    ): AiRequest {
        try {
            if (strlen($renderedPrompt) + strlen($reply) > self::MAX_INPUT_BYTES
                || trim($model) === '' || strlen($model) > 256
                || $promptVersion === '' || strlen($promptVersion) > 128
                || preg_match('/\A[0-9a-f]{64}\z/D', $promptChecksum) !== 1
                || ! mb_check_encoding($renderedPrompt, 'UTF-8')
                || ! mb_check_encoding($reply, 'UTF-8')
                || ! mb_check_encoding($model, 'UTF-8')
                || ! mb_check_encoding($promptVersion, 'UTF-8')) {
                throw new InvalidArgumentException;
            }

            return new AiRequest(
                messages: [['role' => 'system', 'content' => $renderedPrompt], ['role' => 'user', 'content' => $reply]],
                model: $model,
                temperature: 0.0,
                responseFormat: ['type' => 'json_schema', 'json_schema' => [
                    'name' => 'guard', 'strict' => true, 'schema' => [
                        'type' => 'object', 'additionalProperties' => false, 'required' => ['ok', 'issues'],
                        'properties' => ['ok' => ['type' => 'boolean'], 'issues' => ['type' => 'array', 'items' => ['type' => 'string']]],
                    ],
                ]],
                maxTokens: 300,
                promptVersion: $promptVersion,
                promptChecksum: $promptChecksum,
            );
        } catch (Throwable) {
            throw new InvalidArgumentException('Guard request could not be safely prepared.');
        }
    }
}
