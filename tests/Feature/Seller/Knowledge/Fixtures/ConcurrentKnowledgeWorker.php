<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 5).'/vendor/autoload.php';
$app = require dirname(__DIR__, 5).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script,$mode,$id,$path] = $argv;
if (! str_contains(DB::connection()->getDatabaseName(), '_test_')) {
    throw new RuntimeException('Separate test database required.');
}
if ($mode === 'publish') {
    DB::beginTransaction();
    DB::table('knowledge_releases')->where('id', $id)->update(['status' => 'published', 'checksum' => str_repeat('a', 64), 'published_at' => now()]);
    file_put_contents($path.'/locked', '1');
    $deadline = microtime(true) + 15;
    while (! is_file($path.'/release') && microtime(true) < $deadline) {
        usleep(20000);
    }
    if (! is_file($path.'/release')) {
        DB::rollBack();
        exit(2);
    }
    DB::commit();
    exit(0);
}
if ($mode === 'edit') {
    file_put_contents($path.'/started', '1');
    try {
        DB::table('kn_persona')->where('release_id', $id)->update(['data' => '{"name":"modified"}']);
        exit(3);
    } catch (QueryException $e) {
        if (str_contains($e->getMessage(), 'immutable')) {
            exit(0);
        } throw $e;
    }
}
exit(4);
