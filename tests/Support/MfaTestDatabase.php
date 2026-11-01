<?php

namespace Tests\Support;

use Tests\TestCase;

/** Fail closed before fixtures or migration refreshes can touch a non-test DB. */
final class MfaTestDatabase
{
    public static function assert(bool $localFixture = false): void
    {
        TestCase::assertIsolatedTestDatabase($localFixture);
    }
}
