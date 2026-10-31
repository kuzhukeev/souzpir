<?php

use App\Shell\Auth\MfaChallenge;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\MfaTestDatabase;

require dirname(__DIR__, 5).'/vendor/autoload.php';
$app = require dirname(__DIR__, 5).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
MfaTestDatabase::assert();
$data = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
fwrite(STDOUT, 'ready:'.DB::selectOne('select pg_backend_pid() as pid')->pid."\n");
try {
    $user = app(MfaChallenge::class)->verify($data['pending'], $data['safe']);
    fwrite(STDOUT, $user ? "accepted\n" : "denied\n");
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error)."\n");
    exit(1);
}
