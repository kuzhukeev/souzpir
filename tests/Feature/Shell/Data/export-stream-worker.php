<?php

// Test-only subprocess: stdout is reserved for archive bytes (or "done").
use App\Models\User;
use App\Shell\Auth\Http\SessionController;
use App\Shell\Data\DataController;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../../vendor/autoload.php';
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $input = json_decode((string) fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($input) || ! in_array($input['mode'] ?? null, ['stream', 'logout'], true)) {
        throw new RuntimeException('Invalid test worker mode.');
    }
    foreach (['user', 'org', 'brand', 'member', 'session', 'database', 'marker'] as $key) {
        if (! isset($input[$key]) || ! is_string($input[$key]) || $input[$key] === '') {
            throw new RuntimeException('Incomplete test worker input.');
        }
    }
    if (! isset($input['version']) || ! is_int($input['version'])
        || ! $app->environment('testing') || config('database.default') !== 'pgsql'
        || config('database.connections.pgsql.database') !== $input['database']
        || env('DB_TEST_DATABASE') !== $input['database']
        || env('APP_PREVIEW_DATABASE') === $input['database']
        || realpath($app->basePath()) !== realpath(getcwd())) {
        throw new RuntimeException('An isolated testing database and worktree are required.');
    }
    if ($input['mode'] === 'stream' && (! isset($input['export']) || ! is_string($input['export']))) {
        throw new RuntimeException('Missing test export.');
    }

    $testRoot = realpath($app->basePath('.local'));
    $markerDir = realpath(dirname($input['marker']));
    $normalize = static fn (string $path): string => rtrim(strtolower(str_replace('\\', '/', $path)), '/');
    if (! is_string($testRoot) || ! is_string($markerDir)
        || ! ($normalize($markerDir) === $normalize($testRoot) || str_starts_with($normalize($markerDir), $normalize($testRoot).'/'))
        || is_link($input['marker'])) {
        throw new RuntimeException('Worker marker must be in the isolated worktree .local directory.');
    }

    config(['session.driver' => 'array']);
    $session = app('session')->driver('array');
    $session->setId($input['session']);
    $session->start();
    $session->put('auth.version', $input['version']);
    $request = Request::create($input['mode'] === 'stream' ? '/settings/data/exports/'.$input['export'].'/download' : '/logout', $input['mode'] === 'stream' ? 'GET' : 'POST');
    $request->setLaravelSession($session);
    $user = User::whereKey($input['user'])->firstOrFail();
    $request->setUserResolver(static fn () => $user);
    $app->instance('request', $request);
    Auth::guard()->setUser($user);
    $tenant = new TenantContext($input['org'], $input['brand'], $input['user'], $input['member']);
    $pid = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
    $mark = static function () use ($input, $pid): void {
        $payload = json_encode(['pid' => $pid, 'mode' => $input['mode']], JSON_THROW_ON_ERROR);
        if (file_put_contents($input['marker'], $payload, LOCK_EX) !== strlen($payload)) {
            throw new RuntimeException('Could not write worker marker.');
        }
    };

    app(TenantContextRunner::class)->run($tenant, function () use ($input, $request, $mark): void {
        if ($input['mode'] === 'logout') {
            $mark();
            app(SessionController::class)->destroy($request);
            fwrite(STDOUT, 'done');
            return;
        }
        $response = app(DataController::class)->download($request, $input['export']);
        $mark();
        $response->sendContent();
    });
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error).': '.$error->getMessage()."\n");
    exit(1);
}
