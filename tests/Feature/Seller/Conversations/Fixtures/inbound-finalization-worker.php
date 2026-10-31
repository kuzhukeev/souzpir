<?php

use App\Seller\Channels\Core\InboundRecorder;
use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Conversations\Data\TurnOutcome;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$expected = env('DB_TEST_DATABASE');
$identity = DB::selectOne('select current_database() as name, host(inet_server_addr()) as host, rolsuper, rolcreatedb, rolcreaterole from pg_roles where rolname = current_user');
if (! $app->environment('testing') || ! is_string($expected) || ! str_contains($expected, '_test_')
    || env('APP_PREVIEW_DATABASE') === $expected || config('database.connections.pgsql.database') !== $expected
    || $identity->name !== $expected || (! str_starts_with((string) $identity->host, '127.') && $identity->host !== '::1')
    || $identity->rolsuper || $identity->rolcreatedb || $identity->rolcreaterole
    || realpath($app->basePath()) !== realpath(__DIR__.'/../../../../..')) {
    throw new RuntimeException('Unsafe isolated worker.');
}
Http::preventStrayRequests();
Http::fake();
Mail::fake();
Queue::fake();
$data = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$context = new TenantContext($data['organization'], $data['brand'], source: 'job');
file_put_contents($argv[2], (string) DB::selectOne('select pg_backend_pid() as pid')->pid);
if ($argv[1] === 'inbound') {
    $app->make(InboundRecorder::class)->ingest($context, $data['channel'], 'second-synthetic-event', [], $data['conversation'], 'Second synthetic input');
    echo 'inserted';
} elseif ($argv[1] === 'commit') {
    $claim = new TurnClaim('claimed', $data['turn'], $data['conversation'], $data['organization'], $data['brand'], 0, $data['token']);
    $outcome = new TurnOutcome($data['turn'], $data['conversation'], 'completed', 1, $data['agent'], null, null, [],
        ['synthetic' => true], [['id' => $data['agent'], 'role' => 'agent', 'text' => 'Synthetic response']]);
    try {
        $app->make(ConversationRepository::class)->commitTurn($claim, $outcome);
        echo 'committed';
    } catch (HttpExceptionInterface $error) {
        if ($error->getStatusCode() !== 409) {
            throw $error;
        }
        echo 'rejected';
    }
} else {
    throw new RuntimeException('Unknown worker operation.');
}
