<?php

use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiGateway;
use App\Contracts\Ai\AiRequest;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $organization, $brand, $key, $directory, $barrier] = $argv;
config(['runtime.ai_driver' => 'fake', 'features.E0-05.defaults.fixture_directory' => $directory, 'features.E0-05.defaults.reservation_micros' => ['google/gemini-2.5-flash' => 60]]);
$deadline = microtime(true) + 15;
while (! is_file($barrier) && microtime(true) < $deadline) {
    usleep(10000);
}
if (! is_file($barrier)) {
    fwrite(STDERR, 'barrier timeout');
    exit(2);
}
try {
    $context = new AiContext($organization, $brand, 'responder', 'seller', 'brand', $brand, $key, ['knowledge_public']);
    $request = new AiRequest([['role' => 'user', 'content' => 'concurrent test']], 'google/gemini-2.5-flash', 0.5);
    $result = app(TenantContextRunner::class)->run(new TenantContext($organization, $brand, source: 'job'), fn () => app(AiGateway::class)->complete($request, $context));
    echo json_encode(['status' => $result->error['code'] ?? 'completed', 'id' => $result->aiCallId], JSON_THROW_ON_ERROR);
} catch (LogicException $error) {
    echo json_encode(['status' => 'blocked', 'reason' => $error->getMessage()], JSON_THROW_ON_ERROR);
}
