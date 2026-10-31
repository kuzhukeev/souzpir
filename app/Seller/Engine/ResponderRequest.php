<?php

namespace App\Seller\Engine;

use App\Contracts\Ai\AiRequest;
use InvalidArgumentException;
use SensitiveParameter;
use Throwable;

/**
 * Pure request construction only. This class neither loads nor verifies a turn,
 * published prompt, tenant, data classes or model configuration, and cannot
 * authorize a Gateway call. The runtime must establish all of those separately.
 */
final class ResponderRequest
{
    public function build(
        #[SensitiveParameter] string $systemPrompt,
        #[SensitiveParameter] array $history,
        #[SensitiveParameter] array $allowedTools,
        #[SensitiveParameter] string $model,
        #[SensitiveParameter] float $temperature,
        #[SensitiveParameter] string $promptVersion,
        #[SensitiveParameter] string $promptChecksum,
        #[SensitiveParameter] array $toolResults = [],
        #[SensitiveParameter] array $guardIssues = [],
        int $historyTurns = 12,
    ): AiRequest {
        try {
            if ($promptVersion === '' || strlen($promptVersion) > 128 || strlen($model) > 256
                || preg_match('/\A[0-9a-f]{64}\z/D', $promptChecksum) !== 1
                || ! mb_check_encoding($model, 'UTF-8') || ! mb_check_encoding($promptVersion, 'UTF-8')) {
                throw new InvalidArgumentException;
            }
            $composed = (new ResponderMessages)->compose($systemPrompt, $history, $allowedTools, $toolResults, $guardIssues, $historyTurns);
            // The bounded message tree must be encodable before becoming an AI request.
            json_encode($composed['messages'], JSON_THROW_ON_ERROR);

            return new AiRequest(
                messages: $composed['messages'],
                model: $model,
                temperature: $temperature,
                tools: $composed['tools'],
                toolChoice: $composed['toolChoice'],
                maxTokens: 1200,
                promptVersion: $promptVersion,
                promptChecksum: $promptChecksum,
            );
        } catch (Throwable) {
            // Keep request data and dependency exceptions out of logs and previous.
            throw new InvalidArgumentException('Responder request could not be safely prepared.');
        }
    }
}
