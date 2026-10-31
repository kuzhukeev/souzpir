<?php

namespace App\Shell\Audit;

final readonly class AuditEntry
{
    public function __construct(
        public string $action,
        public string $subjectType,
        public string $subjectId,
        public ?array $before = null,
        public ?array $after = null,
        public ?string $requestId = null,
        public ?string $eventId = null,
        public ?string $ip = null,
    ) {}
}
