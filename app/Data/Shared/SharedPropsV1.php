<?php

namespace App\Data\Shared;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SharedPropsV1 extends Data
{
    /**
     * @param  array<int, BrandSummaryData>  $brands
     * @param  array<string, bool>  $can
     * @param  array<string, string>  $translations
     */
    public function __construct(
        public AuthData $auth,
        public ?OrganizationData $organization,
        public ?BrandData $brand,
        public array $brands,
        public ModulesData $modules,
        public array $can,
        public ?int $unread,
        public string $request_id,
        public Theme $theme,
        public array $translations,
        public ZiggyData $ziggy,
    ) {}
}
