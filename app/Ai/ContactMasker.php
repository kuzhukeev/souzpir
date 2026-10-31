<?php

namespace App\Ai;

use InvalidArgumentException;

final class ContactMasker
{
    public function request(array $body): array
    {
        $body['messages'] = $this->messages($body['messages']);
        foreach (['tools', 'tool_choice', 'response_format'] as $key) {
            if (array_key_exists($key, $body)) {
                $body[$key] = $this->schema($body[$key]);
            }
        }

        return $body;
    }

    private function schema(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $this->values($value);
        }
        foreach ($value as $key => $child) {
            if (is_string($key) && $this->values($key) !== $key) {
                throw new InvalidArgumentException('Contact data is not allowed in AI schema keys.');
            }
            if (in_array($key, ['name', 'id', 'role', 'type', 'tool_call_id'], true) && is_string($child)) {
                if ($this->values($child) !== $child) {
                    throw new InvalidArgumentException('Contact data is not allowed in AI identifiers.');
                }

                continue;
            }
            $value[$key] = $this->schema($child);
        }

        return $value;
    }

    public function messages(array $messages): array
    {
        foreach ($messages as &$message) {
            if (! is_array($message) || ! in_array($message['role'] ?? null, ['system', 'user', 'assistant', 'tool'], true)) {
                throw new InvalidArgumentException('Invalid AI message.');
            }
            foreach (['role', 'name', 'tool_call_id'] as $key) {
                if (isset($message[$key]) && (! is_string($message[$key]) || $this->values($message[$key]) !== $message[$key])) {
                    throw new InvalidArgumentException('Contact data is not allowed in AI identifiers.');
                }
            }
            if (array_key_exists('content', $message)) {
                $message['content'] = $this->values($message['content']);
            }
            if (isset($message['tool_calls']) && is_array($message['tool_calls'])) {
                foreach ($message['tool_calls'] as &$call) {
                    if (isset($call['function']['arguments']) && is_string($call['function']['arguments'])) {
                        $args = json_decode($call['function']['arguments'], true, 512, JSON_THROW_ON_ERROR);
                        $call['function']['arguments'] = json_encode($this->values($args), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    }
                }
                unset($call);
                $message['tool_calls'] = $this->schema($message['tool_calls']);
            }
            foreach (array_diff(array_keys($message), ['role', 'name', 'tool_call_id', 'content', 'tool_calls']) as $key) {
                $message[$key] = $this->schema($message[$key]);
            }
        }
        unset($message);

        return $messages;
    }

    private function values(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_string($key) && $this->values($key) !== $key) {
                    throw new InvalidArgumentException('Contact data is not allowed in AI data keys.');
                }
                $value[$key] = $this->values($item);
            }

            return $value;
        }
        if (! is_string($value)) {
            return $value;
        }
        $value = preg_replace('/(?<![\p{L}\p{N}._%+\-])[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}.\-]+\.[\p{L}]{2,}(?![\p{L}\p{N}_])/u', '[EMAIL]', $value);
        if ($value === null) {
            throw new InvalidArgumentException('Cannot safely mask contact data.');
        }
        $value = preg_replace_callback('/(?<![\p{L}\p{N}_])\+?\d(?:[\d ().\-]*\d)(?![\p{L}\p{N}_])/u', function ($match): string {
            $digits = preg_replace('/\D/', '', $match[0]);

            return strlen($digits) >= 10 && strlen($digits) <= 15 ? '[ТЕЛЕФОН]' : $match[0];
        }, $value);
        if ($value === null) {
            throw new InvalidArgumentException('Cannot safely mask contact data.');
        }

        return $value;
    }
}
