<?php

namespace App\Shell\Plans;

use App\Providers\Features\FeatureRegistry;
use App\Shell\Plans\Contracts\UsageContributor;
use App\Shell\Plans\Contracts\UsageQuery;
use App\Shell\Plans\Contracts\UsageReader;
use App\Shell\Plans\Contracts\UsageSnapshot;
use App\Tenancy\TenantContext;
use DateTimeImmutable;
use LogicException;

final class RegisteredUsageReader implements UsageReader
{
    public function __construct(private readonly FeatureRegistry $features) {}

    public function read(TenantContext $context, UsageQuery $query): UsageSnapshot
    {
        $context->requireOrganizationId();
        $metrics = [];
        $claimed = [];
        foreach ($query->metrics as $key) {
            $metrics[$key] = ['value' => null, 'unit' => '', 'status' => 'unavailable'];
        }
        foreach ($this->features->extensions() as $key => $class) {
            if (! str_starts_with($key, 'usage.')) {
                continue;
            }
            if (! is_a($class, UsageContributor::class, true)) {
                throw new LogicException('Invalid usage contributor: '.$key);
            }
            foreach (app($class)->read($context, $query) as $name => $metric) {
                if (! array_key_exists($name, $metrics) || isset($claimed[$name])) {
                    throw new LogicException('Unknown or duplicate usage metric: '.$name);
                }
                if (! is_array($metric) || ! array_key_exists('value', $metric) || ! is_string($metric['unit'] ?? null) || ! in_array($metric['status'] ?? null, ['ready', 'unavailable'], true) || ($metric['status'] === 'unavailable' && $metric['value'] !== null) || ($metric['status'] === 'ready' && (! is_numeric($metric['value']) || $metric['value'] === null))) {
                    throw new LogicException('Invalid usage metric: '.$name);
                }
                $metrics[$name] = $metric;
                $claimed[$name] = true;
            }
        }

        return new UsageSnapshot(new DateTimeImmutable, $metrics);
    }
}
