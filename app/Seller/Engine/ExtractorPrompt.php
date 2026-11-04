<?php

namespace App\Seller\Engine;

use App\Tenancy\TenantContext;
use LogicException;

/** A trusted, tenant-bound prompt pinned for the lifetime of one turn. */
final readonly class ExtractorPrompt
{
    private function __construct(
        public string $organizationId,
        public string $brandId,
        public string $version,
        public string $checksum,
        public string $body,
    ) {}

    public static function verified(TenantContext $context, string $version, string $checksum, string $body): self
    {
        if ($context->organizationId === null || $context->brandId === null || $version === '') {
            throw new LogicException('A brand and prompt version are required.');
        }
        $trusted = file_get_contents(resource_path('views/prompts/'.($version === 'v2' ? 'v2/' : '').'extractor.blade.php'));
        if ($trusted === false || ! hash_equals(hash('sha256', $body), $checksum) || ! hash_equals(hash('sha256', $trusted), $checksum)) {
            throw new LogicException('Extractor template is not the trusted source version.');
        }

        return new self($context->organizationId, $context->brandId, $version, $checksum, $body);
    }
}
