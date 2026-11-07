<?php

namespace App\Seller\Channels\Planned;

use Carbon\CarbonImmutable;
use DateTimeZone;

final class ContactTime
{
    public function parse(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})?$/D', $value)) {
            return null;
        }
        try {
            $normal = str_replace(' ', 'T', $value);
            $offset = preg_match('/(?:Z|[+-]\d{2}:\d{2})$/D', $normal) === 1;
            $hasSeconds = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $normal) === 1;
            $format = $hasSeconds ? 'Y-m-d\\TH:i:s' : 'Y-m-d\\TH:i';
            if ($offset) {
                $normal = str_ends_with($normal, 'Z') ? substr($normal, 0, -1).'+00:00' : $normal;
                $format .= 'P';
            }
            $parsed = \DateTimeImmutable::createFromFormat('!'.$format, $normal, new DateTimeZone($timezone));
            if (! $parsed || $parsed->format($format) !== $normal) {
                return null;
            }

            return CarbonImmutable::instance($parsed)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
