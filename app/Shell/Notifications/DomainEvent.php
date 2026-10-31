<?php

namespace App\Shell\Notifications;

use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class DomainEvent
{
    public function __construct(public array $envelope)
    {
        $catalog = json_decode(file_get_contents(base_path('docs/contracts/registry.json')), true, 512, JSON_THROW_ON_ERROR)['events'];
        $type = $envelope['type'] ?? null;
        $definition = is_string($type) ? ($catalog[$type] ?? null) : null;
        if (! $definition || ($envelope['schema_version'] ?? null) !== 1) {
            throw new InvalidArgumentException('Unknown event or version.');
        }
        foreach (['event_id', 'organization_id', 'brand_id'] as $field) {
            if (! is_string($envelope[$field] ?? null) || ! Str::isUlid($envelope[$field])) {
                throw new InvalidArgumentException('Invalid event tenant or identifier.');
            }
        }
        if (($envelope['actor_id'] ?? null) !== null && (! is_string($envelope['actor_id']) || ! Str::isUlid($envelope['actor_id']))) {
            throw new InvalidArgumentException('Invalid actor.');
        }
        if (! is_string($envelope['occurred_at'] ?? null) || strtotime($envelope['occurred_at']) === false) {
            throw new InvalidArgumentException('Invalid occurrence time.');
        }
        $aggregate = $envelope['aggregate'] ?? [];
        if (! is_array($aggregate) || ! is_string($aggregate['type'] ?? null) || ! is_string($aggregate['id'] ?? null) || ! Str::isUlid($aggregate['id']) || ! is_int($aggregate['version'] ?? null) || $aggregate['version'] < 1) {
            throw new InvalidArgumentException('Invalid aggregate.');
        }
        $data = $envelope['data'] ?? null;
        $expected = $definition['requiredData'];
        if (! is_array($data) || count(array_diff(array_keys($data), $expected)) || count(array_diff($expected, array_keys($data)))) {
            throw new InvalidArgumentException('Invalid event data.');
        }
        foreach ($data as $value) {
            if ((! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value)) || (is_string($value) && mb_strlen($value) > 128)) {
                throw new InvalidArgumentException('Invalid event value.');
            }
        }
        if (array_diff(array_keys($envelope), ['event_id', 'schema_version', 'type', 'occurred_at', 'organization_id', 'brand_id', 'actor_id', 'aggregate', 'data'])) {
            throw new InvalidArgumentException('Unexpected event field.');
        }
    }

    public function isNotification(): bool
    {
        $catalog = json_decode(file_get_contents(base_path('docs/contracts/registry.json')), true, 512, JSON_THROW_ON_ERROR)['events'];

        return $catalog[$this->envelope['type']]['notificationEvent'];
    }
}
