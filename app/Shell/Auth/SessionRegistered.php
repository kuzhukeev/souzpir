<?php

namespace App\Shell\Auth;

/** Local synchronous hook; contains an irreversible fingerprint, never the browser session ID. */
final readonly class SessionRegistered
{
    public function __construct(
        public string $sessionHash,
        public string $userId,
        public ?string $ipAddress,
        public ?string $userAgent,
    ) {}
}
