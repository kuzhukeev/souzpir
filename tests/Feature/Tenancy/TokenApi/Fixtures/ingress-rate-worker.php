<?php

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Tests\Feature\Shell\Support\Fixtures\IsolatedPlanTestDatabase;

require dirname(__DIR__, 5).'/vendor/autoload.php';
$app = require dirname(__DIR__, 5).'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
IsolatedPlanTestDatabase::assert($app);
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$directory = realpath($input['directory'] ?? '');
$root = realpath(base_path('.local/runtime/testing'));
$normalize = static fn (string $path): string => strtolower(str_replace('\\', '/', rtrim($path, '/\\')));
if (! $directory || ! $root || is_link($input['directory']) || ! str_starts_with($normalize($directory), $normalize($root).'/ingress-race-')
    || ! in_array($input['number'] ?? null, [1, 2], true) || config('cache.default') !== 'file') {
    throw new LogicException('Invalid isolated ingress race fixture.');
}
file_put_contents($directory.'/ready-'.$input['number'], 'ready', LOCK_EX);
$deadline = microtime(true) + 20;
while (! is_file($directory.'/start') && microtime(true) < $deadline) {
    usleep(10000);
}
if (! is_file($directory.'/start')) {
    throw new RuntimeException('Isolated race start timed out.');
}
$counts = ['401' => 0, '429' => 0, 'other' => 0];
$kernel = $app->make(HttpKernel::class);
for ($i = 0; $i < 40; $i++) {
    $request = Request::create('https://localhost/api/v1/brand', 'GET', [], [], [], [
        'REMOTE_ADDR' => '192.0.2.77', 'SERVER_ADDR' => '127.0.0.1', 'HTTPS' => 'on',
        'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer 123|'.str_repeat('R', 64),
    ]);
    $response = $kernel->handle($request);
    $status = (string) $response->getStatusCode();
    $counts[array_key_exists($status, $counts) ? $status : 'other']++;
    $kernel->terminate($request, $response);
}
echo json_encode($counts, JSON_THROW_ON_ERROR);
