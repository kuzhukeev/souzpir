<?php

// One isolated HTTP-kernel request per process. No network listener or real mail transport.
use App\Shell\Support\TicketMail;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

require __DIR__.'/../../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

try {
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $database = DB::connection()->getDatabaseName();
    $directory = realpath($input['directory'] ?? '');
    $temporaryRoot = realpath(base_path('.local/runtime'));
    $normalize = static fn (string $path): string => rtrim(str_replace('\\', '/', DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path), '/');
    if (! app()->environment('testing') || config('database.default') !== 'pgsql'
        || ! preg_match('/(?:^|_)test(?:_|$)/i', $database) || $database !== ($input['database'] ?? null)
        || $database !== config('runtime.test_database') || $database === config('runtime.preview_database')
        || realpath(getcwd()) !== realpath(base_path()) || ! is_string($directory) || ! is_string($temporaryRoot)
        || ! str_starts_with($normalize($directory), $normalize($temporaryRoot).'/support-race-')
        || is_link($input['directory']) || ! in_array($input['mode'] ?? null, ['login', 'ready', 'submit'], true)) {
        throw new LogicException('An isolated test database and temporary fixture are required.');
    }

    config([
        'session.driver' => 'file', 'session.files' => $directory.'/sessions',
        'features.E1-12.defaults.mail_enabled' => true, 'features.E1-12.defaults.smtp_enabled' => true,
        'features.E1-12.defaults.mailer' => 'smtp', 'features.E1-12.defaults.recipient' => 'support@example.test',
    ]);
    Http::preventStrayRequests();
    Mail::fake();
    $mode = $input['mode'];
    if ($mode === 'submit') {
        $number = $input['number'] ?? null;
        if (! in_array($number, [1, 2], true) || ! Str::isUlid($input['key'] ?? '')) {
            throw new LogicException('Invalid synthetic submission.');
        }
        $pid = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
        DB::connection()->beforeExecuting(function (string $query) use ($directory, $number, $pid): void {
            if (str_contains($query, '"auth_session_registry"') && stripos($query, 'for update') !== false) {
                file_put_contents($directory.'/waiting-'.$number, (string) $pid, LOCK_EX);
            }
        });
    }
    [$method, $path, $payload] = match ($mode) {
        'login' => ['POST', '/login', ['email' => $input['email'], 'password' => $input['password']]],
        'ready' => ['GET', '/help/support', []],
        'submit' => ['POST', '/help/support', ['subject' => 'technical', 'module' => 'cabinet',
            'body' => 'Synthetic concurrent support request.', 'support_access' => true, 'submission_key' => $input['key']]],
    };
    $request = Request::create('https://example.test'.$path, $method, $payload, server: ['HTTP_ACCEPT' => 'application/json']);
    if ($mode !== 'login') {
        $cookie = base64_decode($input['cookie'] ?? '', true);
        if (! is_string($cookie) || $cookie === '') {
            throw new LogicException('Missing synthetic session cookie.');
        }
        $request->cookies->set(config('session.cookie'), $cookie);
    }
    $kernel = $app->make(HttpKernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    $sessionCookie = null;
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() === config('session.cookie')) {
            $sessionCookie = base64_encode($cookie->getValue());
        }
    }
    $json = $mode === 'submit' ? json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR) : [];
    echo json_encode(['status' => $response->getStatusCode(), 'cookie' => $sessionCookie,
        'ticket' => $json['data']['ticket']['id'] ?? null, 'fake_mail_count' => Mail::sent(TicketMail::class)->count()], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error).': '.$error->getMessage()."\n");
    exit(1);
}
