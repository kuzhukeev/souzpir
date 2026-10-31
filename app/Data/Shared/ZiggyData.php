<?php

namespace App\Data\Shared;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ZiggyData extends Data
{
    /**
     * @param  array<string, string|int|float>  $defaults
     * @param  array<string, ZiggyRouteData>  $routes
     */
    public function __construct(
        public string $url,
        public ?int $port,
        public array $defaults,
        public array $routes,
        public ZiggyLocationData $location,
    ) {}
}
