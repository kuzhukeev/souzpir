<?php

namespace App\Seller\Engine;

use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Resolve a published platform or brand prompt without evaluating arbitrary Blade. */
final class ExtractorPromptLoader
{
    public function load(TenantContext $context, string $version = 'v1'): ExtractorPrompt
    {
        if ($context->organizationId === null || $context->brandId === null || $version === '') {
            throw new LogicException('A brand and prompt version are required.');
        }

        return app(TenantContextRunner::class)->run($context, function () use ($context, $version): ExtractorPrompt {
            $row = DB::table('prompt_sets')->where('name', 'extractor')->where('version', $version)->where('status', 'published')
                ->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)->first();
            $row ??= DB::table('prompt_sets')->where('name', 'extractor')->where('version', $version)->where('status', 'published')
                ->whereNull('organization_id')->whereNull('brand_id')->first();
            if (! $row) {
                throw new LogicException('Published extractor prompt not found.');
            }

            return ExtractorPrompt::verified($context, $row->version, $row->checksum, $row->body);
        });
    }
}
