<?php

use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnCommand;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing')) {
    throw new RuntimeException('Test environment required');
}
[$script, $barrier, $organization, $brand, $conversation, $message] = $argv;
$event = $argv[6] ?? null;
$key = $argv[7] ?? 'concurrent-key';
$deadline = microtime(true) + 10;
while (! file_exists($barrier)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Barrier timeout');
    }
    usleep(10000);
}
try {
    $claim = $app->make(ConversationRepository::class)->claimTurn(new TenantContext($organization, $brand, source: 'job'), new TurnCommand($conversation, $message, 'Concurrent', $event, $key, 0));
    echo $claim->status;
} catch (HttpException $e) {
    if ($e->getStatusCode() !== 409) {
        throw $e;
    }
    echo 'conflict';
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage());
    exit(1);
}
