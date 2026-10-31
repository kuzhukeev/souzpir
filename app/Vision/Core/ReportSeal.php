<?php

namespace App\Vision\Core;

use App\Ai\CanonicalJson;

final class ReportSeal
{
    public static function mac(string $organization, string $brand, string $report, string $measurement, int $revision, string $checksum): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new \LogicException('Report signing is unavailable.');
        }

        return hash_hmac('sha256', 'vision-report:'.CanonicalJson::hash([
            'organization' => $organization, 'brand' => $brand, 'report' => $report,
            'measurement' => $measurement, 'revision' => $revision, 'checksum' => $checksum,
        ]), $key);
    }
}
