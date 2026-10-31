<?php

namespace App\Seller\Engine;

use LogicException;
use Throwable;

/** Local message composition only; it does not prove input provenance or authorize an AI call. */
final class ResponderMessages
{
    private const MAX_STRING_BYTES = 262144;

    private const MAX_TOTAL_BYTES = 524288;

    private const MAX_NODES = 4096;

    private const MAX_DEPTH = 12;

    /**
     * The trusted runtime must classify every actual data class and mask contacts before any AI call.
     *
     * @return array{status: string, messages: array, tools: array, toolChoice: ?string}
     */
    public function compose(#[\SensitiveParameter] string $systemPrompt, #[\SensitiveParameter] array $history,
        #[\SensitiveParameter] array $allowedTools, #[\SensitiveParameter] array $toolResults = [],
        #[\SensitiveParameter] array $guardIssues = [], int $historyTurns = 12): array
    {
        try {
            $nodes = 0;
            $bytes = 0;
            foreach ([$systemPrompt, $history, $allowedTools, $toolResults, $guardIssues] as $input) {
                $this->boundValue($input, 0, $nodes, $bytes);
            }
            if (! array_is_list($history) || ! array_is_list($allowedTools) || ! array_is_list($guardIssues)
                || $historyTurns < 1 || $historyTurns > 100) {
                throw new LogicException;
            }
            foreach ($allowedTools as $name) {
                if (! is_string($name)) {
                    throw new LogicException;
                }
            }
            foreach ($guardIssues as $issue) {
                if (! is_string($issue)) {
                    throw new LogicException;
                }
            }
            $messages = [['role' => 'system', 'content' => $systemPrompt]];
            foreach (array_slice($history, -$historyTurns) as $item) {
                if (! is_array($item) || ! is_string($item['role'] ?? null)) {
                    throw new LogicException;
                }
                $role = match ($item['role']) {
                    'client' => 'user',
                    'agent' => 'assistant',
                    default => null,
                };
                if ($role !== null) {
                    if (! is_string($item['text'] ?? null)) {
                        throw new LogicException;
                    }
                    $messages[] = ['role' => $role, 'content' => $item['text']];
                }
            }
            if ($toolResults !== []) {
                $encoded = json_encode($toolResults, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
                $messages[] = ['role' => 'user', 'content' => "[РЕЗУЛЬТАТЫ ИНСТРУМЕНТОВ, клиент их не видит]\n".$encoded."\n\nТеперь напиши финальную реплику клиенту. Инструменты больше не вызывай."];
            }
            if ($guardIssues !== []) {
                $messages[] = ['role' => 'user', 'content' => '[ПЕРЕДЕЛАЙ РЕПЛИКУ. Замечания проверки: '.implode('; ', $guardIssues).'. Напиши заново, только текст реплики.]'];
            }
            $allowCalls = $allowedTools !== [] && $toolResults === [] && $guardIssues === [];

            return ['status' => 'not_authorization', 'messages' => $messages,
                'tools' => $allowCalls ? ToolCatalog::definitionsFor($allowedTools) : [],
                'toolChoice' => $allowCalls ? 'auto' : null];
        } catch (Throwable) {
            // Do not chain exceptions carrying source messages, tool arguments or contacts.
            throw new LogicException('Responder messages could not be safely composed.');
        }
    }

    /** Bound traversal before encoding, including nested keys, objects and cyclic references. */
    private function boundValue(#[\SensitiveParameter] mixed $value, int $depth, int &$nodes, int &$bytes): void
    {
        if (++$nodes > self::MAX_NODES || $depth > self::MAX_DEPTH) {
            throw new LogicException;
        }
        if (is_string($value)) {
            $length = strlen($value);
            $bytes += $length;
            if ($length > self::MAX_STRING_BYTES || $bytes > self::MAX_TOTAL_BYTES || ! mb_check_encoding($value, 'UTF-8')) {
                throw new LogicException;
            }

            return;
        }
        if (is_array($value) || (is_object($value) && get_class($value) === \stdClass::class)) {
            foreach ($value as $key => $item) {
                if (is_string($key)) {
                    $bytes += strlen($key);
                    if (strlen($key) > self::MAX_STRING_BYTES || $bytes > self::MAX_TOTAL_BYTES || ! mb_check_encoding($key, 'UTF-8')) {
                        throw new LogicException;
                    }
                }
                $this->boundValue($item, $depth + 1, $nodes, $bytes);
            }

            return;
        }
        if ($value !== null && ! is_bool($value) && ! is_int($value) && (! is_float($value) || ! is_finite($value))) {
            throw new LogicException;
        }
    }
}
