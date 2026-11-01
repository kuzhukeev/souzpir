<?php

namespace App\Ai;

final class GatewayErrorMessage
{
    public static function sanitize(string $message): string
    {
        $allowed = [
            'AI calls cannot run inside a database transaction.',
            'The requested AI provider/model is not active.',
            'Unsupported AI driver.',
            'Recording is only allowed in local/testing.',
            'AI data could not be safely prepared.',
            'AI call state changed before response persistence.',
            'AI idempotency key reused with different request or context.',
            'AI attempt requires a matching parent.',
            'AI parent attempt cannot be retried.',
            'AI parent attempt already has a retry.',
            'AI unit budget requires an approved conversion rule.',
            'AI reservation was exceeded and requires operator review.',
            'A configured live budget requires bounded request sizes.',
            'AI request exceeds the configured reservation bounds.',
            'A configured budget requires a model reservation bound.',
            'An uncertain or unreserved AI charge requires reconciliation before further spending.',
            'AI budget exhausted.',
            'AI reservation cannot be settled.',
            'Confirmed result and cost are required.',
            'Successful reconciliation requires a verified provider evidence workflow.',
            'Unbilled reconciliation requires a verified provider evidence workflow.',
            'Active tenant and evidence are required.',
            'AI call not found.',
            'Invalid AI budget threshold.',
            'AI budget notification requires the E0-04 transactional outbox.',
            'Invalid AI driver result.',
            'Invalid AI driver usage.',
            'Invalid AI tool call.',
            'AI context does not match the active tenant.',
            'Unsupported AI cost object.',
            'AI cost object storage is unavailable.',
            'AI cost object is outside the active tenant.',
            'Live AI calls are disabled.',
            'Brand data policy does not authorize this external AI transfer.',
            'Cannot create private AI recording directory.',
            'Cannot write private AI recording.',
        ];

        return in_array($message, $allowed, true) ? $message : 'AI request could not be safely completed.';
    }
}
