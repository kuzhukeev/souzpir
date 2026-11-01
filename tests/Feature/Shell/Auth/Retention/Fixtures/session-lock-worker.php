<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require __DIR__.'/../../../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestCase::assertIsolatedTestDatabase();
$hash = trim((string) fgets(STDIN));
DB::beginTransaction();
DB::table('auth_session_registry')->where('session_hash', $hash)->lockForUpdate()->first();
echo "locked\n";
flush();
fgets(STDIN);
DB::rollBack();
echo "released\n";
