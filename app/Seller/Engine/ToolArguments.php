<?php

namespace App\Seller\Engine;

use SensitiveParameter;
use Throwable;

/**
 * Shape check for already-decoded gateway arguments only; not an authorization.
 * The gateway's associative decode loses duplicate JSON keys and cannot distinguish
 * an empty object from an empty list. This check cannot establish either property.
 */
final class ToolArguments
{
    private const MAX_BYTES = 16384;

    private const MAX_LIST_ITEMS = 100;

    /** @return array{valid: bool, code: string} Fixed metadata only; never returns arguments. */
    public function check(#[SensitiveParameter] mixed $name, #[SensitiveParameter] mixed $arguments): array
    {
        try {
            if (! is_string($name) || strlen($name) > 64 || ! is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
                return $this->invalid();
            }
            $definition = ToolCatalog::definitions()[$name]['function']['parameters'] ?? null;
            if (! is_array($definition)) {
                return $this->invalid();
            }
            $properties = $definition['properties'] instanceof \stdClass ? [] : $definition['properties'];
            if (count($arguments) > count($properties)) {
                return $this->invalid();
            }
            foreach ($definition['required'] as $required) {
                if (! array_key_exists($required, $arguments)) {
                    return $this->invalid();
                }
            }
            $bytes = 0;
            foreach ($arguments as $key => $value) {
                // The donor does not reject extra properties; fail closed until a runtime policy approves them.
                if (! is_string($key) || ! isset($properties[$key])) {
                    return $this->invalid();
                }
                $bytes += strlen($key);
                if ($bytes > self::MAX_BYTES || ! $this->matches($value, $properties[$key], $bytes)) {
                    return $this->invalid();
                }
            }

            return ['valid' => true, 'code' => 'shape_only'];
        } catch (Throwable) {
            return $this->invalid();
        }
    }

    private function matches(#[SensitiveParameter] mixed $value, array $schema, int &$bytes): bool
    {
        if (($schema['type'] ?? null) === 'string') {
            return $this->addString($value, $bytes);
        }
        if (($schema['type'] ?? null) === 'number') {
            $bytes += 32;

            return $bytes <= self::MAX_BYTES && (is_int($value) || is_float($value)) && is_finite((float) $value);
        }
        if (($schema['type'] ?? null) !== 'array' || ! is_array($value) || ! array_is_list($value)
            || count($value) > self::MAX_LIST_ITEMS || ($schema['items']['type'] ?? null) !== 'string') {
            return false;
        }
        foreach ($value as $item) {
            if (! $this->addString($item, $bytes)) {
                return false;
            }
        }

        return true;
    }

    private function addString(#[SensitiveParameter] mixed $value, int &$bytes): bool
    {
        if (! is_string($value)) {
            return false;
        }
        $bytes += strlen($value);

        return $bytes <= self::MAX_BYTES && preg_match('//u', $value) === 1;
    }

    private function invalid(): array
    {
        return ['valid' => false, 'code' => 'invalid_tool_arguments'];
    }
}
