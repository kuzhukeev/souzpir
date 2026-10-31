<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;

require __DIR__.'/../../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('cache.default') !== 'file') {
    throw new LogicException('Isolated file cache test required.');
}
[$script, $mode, $key, $barrier] = $argv;
if (! str_starts_with($barrier, base_path('.local/tmp/rollout-lock-'))) {
    throw new LogicException('Unsafe barrier.');
}
$lock = Cache::store('file')->lock($key, 30);
if ($mode === 'holder') {
    if (! $lock->get()) {
        throw new LogicException('First lock unavailable.');
    }
    file_put_contents($barrier.'.held', '1');
    $deadline = microtime(true) + 15;
    while (! is_file($barrier.'.release') && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (! is_file($barrier.'.release')) {
        throw new LogicException('Holder timeout.');
    }
    $lock->release();
} elseif ($mode === 'waiter') {
    file_put_contents($barrier.'.waiting', '1');
    $lock->block(10, function () use ($barrier): void {
        file_put_contents($barrier.'.acquired', '1');
    });
} else {
    throw new LogicException('Unknown mode.');
}
