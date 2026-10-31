<?php

namespace App\Contracts\Ai;

use InvalidArgumentException;

final readonly class AiRequest
{
    public function __construct(
        #[\SensitiveParameter] public array $messages,
        public string $model,
        public float $temperature,
        #[\SensitiveParameter] public ?array $responseFormat = null,
        #[\SensitiveParameter] public array $tools = [],
        #[\SensitiveParameter] public mixed $toolChoice = null,
        public ?int $maxTokens = null,
        public ?string $promptVersion = null,
        public ?string $promptChecksum = null,
    ) {
        if (! array_is_list($messages) || $messages === [] || trim($model) === '' || ! is_finite($temperature) || $temperature < 0 || $temperature > 2 || ($maxTokens !== null && $maxTokens < 1) || ! array_is_list($tools)) {
            throw new InvalidArgumentException('Invalid AI request.');
        }
    }

    public function toArray(): array
    {
        return array_filter([
            'messages' => $this->messages, 'model' => $this->model, 'temperature' => $this->temperature,
            'response_format' => $this->responseFormat, 'tools' => $this->tools ?: null,
            'tool_choice' => $this->toolChoice, 'max_tokens' => $this->maxTokens,
            'prompt_version' => $this->promptVersion, 'prompt_checksum' => $this->promptChecksum,
        ], fn ($value) => $value !== null);
    }
}
