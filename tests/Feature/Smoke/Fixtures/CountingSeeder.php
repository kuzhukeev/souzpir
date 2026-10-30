<?php

namespace Tests\Feature\Smoke\Fixtures;

use Illuminate\Database\Seeder;

class CountingSeeder extends Seeder
{
    public static int $runs = 0;

    public function run(): void
    {
        self::$runs++;
    }
}
