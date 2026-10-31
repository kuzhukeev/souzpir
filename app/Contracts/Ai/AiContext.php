<?php

namespace App\Contracts\Ai;

use InvalidArgumentException;

final readonly class AiContext
{
    public function __construct(
        #[\SensitiveParameter] public string $organizationId,
        #[\SensitiveParameter] public string $brandId,
        #[\SensitiveParameter] public string $purpose,
        #[\SensitiveParameter] public string $family,
        #[\SensitiveParameter] public string $objectType,
        #[\SensitiveParameter] public string $objectId,
        #[\SensitiveParameter] public string $idempotencyKey,
        #[\SensitiveParameter] public array $dataClasses,
        #[\SensitiveParameter] public int $attempt = 1,
        #[\SensitiveParameter] public ?string $parentCallId = null,
    ) {
        if (! in_array($family, ['seller', 'vision'], true) || $purpose === '' || $objectType === '' || $objectId === '' || $idempotencyKey === '' || strlen($idempotencyKey) > 255 || $attempt < 1 || ! array_is_list($dataClasses) || $dataClasses === [] || count(array_filter($dataClasses, fn ($value) => is_string($value) && $value !== '')) !== count($dataClasses)) {
            throw new InvalidArgumentException('Invalid AI context.');
        }
    }
}
