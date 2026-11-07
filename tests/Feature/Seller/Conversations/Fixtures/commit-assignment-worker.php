<?php

use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Conversations\Data\TurnOutcome;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 5).'/vendor/autoload.php';
if (getenv('APP_ENV') !== 'testing') {
    exit(2);
}
$app = require dirname(__DIR__, 5).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection();
if (! $app->environment('testing') || $db->getDriverName() !== 'pgsql'
    || ! preg_match('/(?:^|_)test(?:_|$)/', $db->getDatabaseName())
    || $db->getDatabaseName() !== config('runtime.test_database')
    || $db->getDatabaseName() === config('runtime.preview_database')
    || $db->selectOne('SELECT current_database() AS name')->name !== $db->getDatabaseName()) {
    exit(2);
}
fwrite(STDOUT, 'ready:'.DB::scalar('SELECT pg_backend_pid()')."\n");
$input = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
$claim = new TurnClaim('claimed', $input['turn'], $input['conversation'], $input['org'], $input['brand'], 0, $input['token']);
$outcome = new TurnOutcome($input['turn'], $input['conversation'], 'completed', 1, null, null, null, [], [], tasks: [['id' => $input['task'], 'type' => 'followup', 'assigned_user_id' => $input['user']]]);
try {
    if ($input['order'] === 'commit-first') {
        DB::beginTransaction();
    }
    app(ConversationRepository::class)->commitTurn($claim, $outcome);
    if ($input['order'] === 'commit-first') {
        fwrite(STDOUT, "assigned\n");
        fgets(STDIN);
        DB::commit();
        fwrite(STDOUT, "committed\n");
    } else {
        fwrite(STDOUT, "accepted\n");
    }
} catch (HttpException $exception) {
    fwrite(STDOUT, 'rejected:'.$exception->getStatusCode()."\n");
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(3);
}
