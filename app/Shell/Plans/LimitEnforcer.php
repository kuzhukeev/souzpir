<?php

namespace App\Shell\Plans;

use App\Shell\Plans\Contracts\PlanLimits;
use App\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class LimitEnforcer
{
    public function __construct(private readonly PlanLimits $limits) {}

    public function assertWithin(TenantContext $context, string $key, string $proposed): void
    {
        $parts = $this->parts($proposed);
        $limit = $this->limits->get($context, $key);
        if (! $limit->configured) {
            return;
        }
        $max = $this->parts($limit->value ?? throw new InvalidArgumentException('Configured limit has no value.'));
        $scale = max(strlen($parts[1]), strlen($max[1]));
        $larger = strlen($parts[0]) > strlen($max[0]) || (strlen($parts[0]) === strlen($max[0]) && (strcmp($parts[0], $max[0]) > 0 || ($parts[0] === $max[0] && strcmp(str_pad($parts[1], $scale, '0'), str_pad($max[1], $scale, '0')) > 0)));
        if ($larger) {
            throw ValidationException::withMessages([$key => 'Превышен лимит тарифа.']);
        }
    }

    private function parts(string $value): array
    {
        if (! preg_match('/^\d+(?:\.\d+)?$/D', $value)) {
            throw new InvalidArgumentException('A non-negative decimal string is required.');
        }
        $parts = explode('.', $value, 2);

        return [ltrim($parts[0], '0') ?: '0', $parts[1] ?? ''];
    }
}
