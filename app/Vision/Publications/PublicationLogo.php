<?php

namespace App\Vision\Publications;

use App\Shell\Brands\Actions\BrandSettings;
use App\Shell\Onboarding\SvgLogo;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class PublicationLogo
{
    public function copy(TenantContext $context): ?string
    {
        $raw = DB::table('brands')->where('organization_id', $context->organizationId)->where('id', $context->brandId)->value('settings');
        $settings = is_string($raw) ? json_decode($raw, true) : (array) $raw;
        $path = $settings['logo_path'] ?? null;
        if (! is_string($path) || ! BrandSettings::validLogoPath($context->organizationId, $path)
            || ! Storage::disk('local')->exists($path) || Storage::disk('local')->size($path) > 4 * 1024 * 1024) {
            return null;
        }
        $bytes = Storage::disk('local')->get($path);
        if ($bytes === '') {
            return null;
        }
        if (str_ends_with($path, '.svg')) {
            $safe = SvgLogo::sanitize($bytes);

            return $safe === null ? null : 'data:image/svg+xml;base64,'.base64_encode($safe);
        }
        $info = @getimagesizefromstring($bytes);
        if (! $info || ($info['mime'] ?? null) !== 'image/png' || $info[0] < 1 || $info[1] < 1 || $info[0] > 2048 || $info[1] > 2048) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode($bytes);
    }
}
