<?php

namespace App\Http;

use Illuminate\Support\Str;

/** Anonymous props for the isolated preview, not production authorization. */
final class PreviewProps
{
    public static function make(): array
    {
        return [
            'auth' => ['user' => null],
            'organization' => null,
            'brand' => null,
            'brands' => [],
            'modules' => ['seller' => false, 'vision' => false],
            'can' => [],
            'unread' => 0,
            'request_id' => (string) Str::uuid(),
            'preview' => true,
        ];
    }
}
