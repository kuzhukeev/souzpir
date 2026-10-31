<?php

namespace App\Ai\Protected;

use App\Ai\CanonicalJson;
use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiRequest;
use SensitiveParameter;

/** Same Gateway idempotency digest for the actual request and its context. */
final class RequestFingerprint
{
    public function hash(#[SensitiveParameter] AiRequest $request, #[SensitiveParameter] AiContext $context, #[SensitiveParameter] array $maskedBody, string $driver): string
    {
        return CanonicalJson::hash([
            'request' => $maskedBody,
            'original_request_hmac' => hash_hmac('sha256', CanonicalJson::hash($request->toArray()), (string) config('app.key')),
            'family' => $context->family, 'purpose' => $context->purpose,
            'object_type' => $context->objectType, 'object_id' => $context->objectId,
            'data_classes' => $context->dataClasses, 'attempt' => $context->attempt,
            'parent_call_id' => $context->parentCallId,
            'driver' => $driver,
        ]);
    }
}
