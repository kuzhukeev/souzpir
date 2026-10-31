<?php

use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\Http\SessionController;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\IsolatedAuthDatabase;

require __DIR__.'/../../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
IsolatedAuthDatabase::assert();
$data = json_decode((string) fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
$user = User::findOrFail($data['user']);
$session = app('session')->driver();
$session->setId($data['session']);
$session->start();
$session->put('auth.version', $data['version']);
Auth::guard()->setUser($user);
$request = Request::create('/logout', 'POST');
$request->setLaravelSession($session);
$request->setUserResolver(fn () => $user);
DB::statement("SET lock_timeout = '15s'");
echo 'ready:'.DB::selectOne('select pg_backend_pid() as pid')->pid."\n";
flush();
try {
    if ($data['hold']) {
        DB::beginTransaction();
    }
    if ($data['operation'] === 'advance') {
        app(AuthSessionRegistry::class)->withinActive($request, function (User $fresh) use ($request): void {
            $fresh->forceFill(['auth_version' => $fresh->auth_version + 1])->save();
            app(AuthSessionRegistry::class)->advanceVersion($request, $fresh->auth_version);
        });
    } elseif ($data['operation'] === 'logout') {
        if (app(SessionController::class)->destroy($request)->getStatusCode() !== 302) {
            throw new RuntimeException('Unexpected logout result.');
        }
    } else {
        app(AuthSessionRegistry::class)->withinActive($request, static fn () => null);
    }
    echo "applied\n";
    flush();
    if ($data['hold']) {
        stream_set_timeout(STDIN, 20);
        if (trim((string) fgets(STDIN)) !== 'continue') {
            throw new RuntimeException('Barrier timeout.');
        }
        DB::commit();
    }
    echo "committed\n";
} catch (AuthenticationException) {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    echo "denied\n";
} catch (Throwable $error) {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    fwrite(STDERR, get_class($error)."\n");
    echo "failed\n";
    exit(1);
}
