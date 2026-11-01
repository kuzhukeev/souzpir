<?php

namespace App\Ai\Drivers;

use App\Ai\Protected\RawToolArguments;
use App\Privacy\Secrets\JsonObjectInspector;
use InvalidArgumentException;
use SensitiveParameter;
use Throwable;

/** Parsing only: returned data does not authorize a stage or an action. */
final class ProtectedRawToolCalls
{
    public function decodeEnvelope(#[SensitiveParameter] string $json): array
    {
        try {
            return (new JsonObjectInspector)->decode($json);
        } catch (Throwable) {
            throw new InvalidArgumentException('Protected AI response is invalid.');
        }
    }

    /** @return array{normalized: array, raw: array} */
    public function parse(#[SensitiveParameter] array $calls, bool $provider): array
    {
        try {
            if (! array_is_list($calls)) {
                throw new InvalidArgumentException;
            }
            $normalized = [];
            $raw = [];
            $ids = [];
            foreach ($calls as $call) {
                $function = $provider ? ($call['function'] ?? null) : $call;
                if (! is_array($call) || ! is_array($function) || ! is_string($call['id'] ?? null) || $call['id'] === ''
                    || isset($ids[$call['id']]) || ! is_string($function['name'] ?? null) || $function['name'] === ''
                    || ! is_string($function['arguments'] ?? null) || ! app(RawToolArguments::class)->accepts($function['arguments'])) {
                    throw new InvalidArgumentException;
                }
                $ids[$call['id']] = true;
                $arguments = $function['arguments'];
                $raw[] = ['id' => $call['id'], 'name' => $function['name'], 'arguments_json' => $arguments];
                $normalized[] = ['id' => $call['id'], 'name' => $function['name'], 'arguments' => json_decode($arguments, true, 22, JSON_THROW_ON_ERROR)];
            }

            return ['normalized' => $normalized, 'raw' => $raw];
        } catch (Throwable) {
            throw new InvalidArgumentException('Protected tool arguments are invalid.');
        }
    }
}
