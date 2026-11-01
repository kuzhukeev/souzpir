<?php

namespace Tests\Support;

use Tests\TestCase;

final class IsolatedAuthDatabase
{
    public static function assert(): void
    {
        TestCase::assertIsolatedTestDatabase();
    }
}
