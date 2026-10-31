<?php

declare(strict_types=1);

use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnCommand;
use App\Shell\Notifications\Contracts\NotificationDispatcher;
use App\Shell\Notifications\DomainEvent;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Diagnostic only: a positive result confirms the known caller-closure leak.
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
$database = config('database.connections.pgsql.database');
if (! $app->environment('testing') || ! is_string($database) || ! str_contains($database, '_test_')
    || DB::selectOne('select current_database() as name')->name !== $database
    || ini_get('zend.exception_ignore_args') !== '0') {
    throw new RuntimeException('Isolated testing database and exception arguments enabled are required.');
}

function capturedMarker(Throwable $error, string $marker): bool
{
    foreach ($error->getTrace() as $frame) {
        foreach ($frame['args'] ?? [] as $argument) {
            if ($argument instanceof Closure && str_contains(print_r((new ReflectionFunction($argument))->getStaticVariables(), true), $marker)) {
                return true;
            }
        }
    }

    return false;
}

$id = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
$event = new DomainEvent([
    'event_id' => $id, 'organization_id' => $id, 'brand_id' => $id, 'actor_id' => null,
    'schema_version' => 1, 'type' => 'seller.conversation.handed_off', 'occurred_at' => '2026-10-31T00:00:00Z',
    'aggregate' => ['type' => 'conversation', 'id' => $id, 'version' => 1],
    'data' => ['reason' => "SYNTHETIC_OUTBOX_MARKER\xff", 'task_id' => $id],
]);
$outboxLeaked = false;
try {
    DB::transaction(fn () => app(NotificationDispatcher::class)->enqueue($event));
} catch (Throwable $error) {
    $outboxLeaked = capturedMarker($error, 'SYNTHETIC_OUTBOX_MARKER');
}

$tenant = new TenantContext($id, $id, source: 'job');
$command = new TurnCommand('invalid', 'invalid', 'SYNTHETIC_SELLER_MARKER', null, 'key', 0);
$sellerLeaked = false;
try {
    DB::transaction(fn () => app(ConversationRepository::class)->claimTurn($tenant, $command));
} catch (Throwable $error) {
    $sellerLeaked = capturedMarker($error, 'SYNTHETIC_SELLER_MARKER');
}

echo json_encode(['outbox_caller_closure_leaks' => $outboxLeaked, 'seller_caller_closure_leaks' => $sellerLeaked], JSON_THROW_ON_ERROR).PHP_EOL;
exit($outboxLeaked && $sellerLeaked ? 0 : 1);
