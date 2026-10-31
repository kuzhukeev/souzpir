<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing')) {
    throw new RuntimeException('Test environment required');
}
[$script, $barrier, $op, $org, $brand, $conversation, $channel] = $argv;
$deadline = microtime(true) + 10;
while (! file_exists($barrier)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Barrier timeout');
    }
    usleep(10000);
}
try {
    if ($op === 'change') {
        DB::table('conversations')->where('id', $conversation)->update(['channel_account_id' => $channel]);
    } else {
        DB::table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'conversation_id' => $conversation, 'role' => 'client', 'text' => 'internal']);
    }
    echo 'success';
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage());
    exit(1);
}
