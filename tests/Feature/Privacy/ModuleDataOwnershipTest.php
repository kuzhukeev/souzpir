<?php

namespace Tests\Feature\Privacy;

use App\Privacy\ModuleDataOwnership;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Tests\TestCase;

final class ModuleDataOwnershipTest extends TestCase
{
    public function test_defines_owner_and_retention_for_both_modules(): void
    {
        $this->assertStringContainsString('App\\Seller', ModuleDataOwnership::owner('seller'));
        $this->assertStringContainsString('App\\Vision', ModuleDataOwnership::owner('vision'));
        $this->assertSame(90, ModuleDataOwnership::RETENTION_DAYS);

        $disabledAt = CarbonImmutable::parse('2026-01-15 12:00:00+00');
        $this->assertSame('2026-04-15T12:00:00+00:00', ModuleDataOwnership::retentionReviewAt($disabledAt)->toIso8601String());
    }

    public function test_rejects_unknown_module(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ModuleDataOwnership::owner('unknown');
    }
}
