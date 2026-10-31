<?php

namespace App\Contracts\Ai;

final readonly class AiResult
{
    public function __construct(
        public ?string $content,
        public array $toolCalls,
        public array $usage,
        public string $model,
        public int $latencyMs,
        public ?int $costMicros,
        public string $aiCallId,
        public ?array $error = null,
    ) {}

    public function ok(): bool
    {
        return $this->error === null;
    }

    /** Preserve the prototype's fenced JSON and embedded-object parsing behavior. */
    public function json(): ?array
    {
        if ($this->content === null) {
            return null;
        }
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', trim($this->content));
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        if (preg_match('/\{.*\}/su', $text, $match)) {
            $decoded = json_decode($match[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $value): self
    {
        return new self($value['content'], $value['toolCalls'], $value['usage'], $value['model'], $value['latencyMs'], $value['costMicros'], $value['aiCallId'], $value['error']);
    }
}
