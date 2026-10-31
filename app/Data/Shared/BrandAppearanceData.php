<?php

namespace App\Data\Shared;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class BrandAppearanceData extends Data
{
    public function __construct(
        public ?string $logo_url,
        public string $subtitle,
        public bool $promo_card,
    ) {}
}
