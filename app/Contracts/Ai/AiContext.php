<?php

namespace App\Contracts\Ai;

use InvalidArgumentException;

final readonly class AiContext
{
    public function __construct(
        public string $organizationId,
        public string $brandId,
        public string $purpose,
        public string $family,
        public string $objectType,
        public string $objectId,
        public string $idempotencyKey,
        public array $dataClasses,
        public int $attempt = 1,
        public ?string $parentCallId = null,
    ) {
        if (! in_array($family, ['seller', 'vision'], true) || $purpose === '' || $objectType === '' || $objectId === '' || $idempotencyKey === '' || strlen($idempotencyKey) > 255 || $attempt < 1 || ! array_is_list($dataClasses) || $dataClasses === [] || count(array_filter($dataClasses, fn ($value) => is_string($value) && $value !== '')) !== count($dataClasses)) {
            throw new InvalidArgumentException('Invalid AI context.');
        }
    }
}
