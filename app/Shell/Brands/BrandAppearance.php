<?php

namespace App\Shell\Brands;

use App\Models\Brand;
use App\Providers\Features\SharedPropsContributor;
use Illuminate\Http\Request;

/** Feature-owned extension; shared shell rendering remains owned by the integrator. */
final class BrandAppearance implements SharedPropsContributor
{
    public function share(Request $request): array
    {
        return ['brandAppearance' => static function (): ?array {
            $brand = Brand::query()->first();
            if (! $brand) {
                return null;
            }

            return ['logo_url' => isset($brand->settings['logo_path']) ? route('brands.settings.logo', $brand->id) : null, 'subtitle' => $brand->settings['theme']['subtitle'] ?? '', 'promo_card' => $brand->settings['theme']['promo_card'] ?? true];
        }];
    }
}
