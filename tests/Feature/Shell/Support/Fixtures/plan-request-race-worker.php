<?php

// Separate process, database connection, and server-side session per worker. No network or SMTP.
use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Support\Contracts\PlanRequestCommand;
use App\Shell\Support\Contracts\PlanRequestPort;
use App\Shell\Support\TicketMail;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require __DIR__.'/../../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $directory = realpath($input['directory'] ?? '');
    $root = realpath(base_path('.local/runtime'));
    $normalize = static fn (string $path): string => strtolower(str_replace('\\', '/', rtrim($path, '/\\')));
    $server = DB::selectOne('select current_database() as db, current_user as role, inet_server_addr() as host, inet_server_port() as port, rolsuper, rolcreatedb, rolcreaterole from pg_roles where rolname = current_user');
    if (! app()->environment('testing') || realpath(getcwd()) !== realpath(base_path())
        || ! is_string($directory) || ! is_string($root) || is_link($input['directory'])
        || ! str_starts_with($normalize($directory), $normalize($root).'/plan-request-race-')
        || ! $server || $server->db !== 'souzpir_test_sp_plan_request' || $server->role !== 'sp_plan_app'
        || $server->host !== '127.0.0.1' || (int) $server->port !== 55531 || $server->rolsuper || $server->rolcreatedb || $server->rolcreaterole
        || ! in_array($input['number'] ?? null, [1, 2], true)) {
        throw new LogicException('Isolated plan request test database and directory required.');
    }
    foreach (['user', 'organization', 'brand', 'member', 'plan', 'key'] as $field) {
        if (! is_string($input[$field] ?? null) || ! Str::isUlid($input[$field])) {
            throw new LogicException('Invalid synthetic identifier.');
        }
    }
    config(['session.driver' => 'array', 'features.E1-12.defaults.mail_enabled' => true, 'features.E1-12.defaults.smtp_enabled' => true,
        'features.E1-12.defaults.mailer' => 'smtp', 'features.E1-12.defaults.recipient' => 'support@example.test']);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $user = User::query()->findOrFail($input['user']);
    session()->start();
    session()->put(['auth.version' => $user->auth_version, 'brand_id' => $input['brand']]);
    Auth::setUser($user);
    $request = Request::create('https://example.test/_synthetic/plan-request', 'POST');
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn () => $user);
    app(AuthSessionRegistry::class)->issue($request, $user, $user->auth_version);
    $pid = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
    file_put_contents($directory.'/ready-'.$input['number'], (string) $pid, LOCK_EX);
    $deadline = microtime(true) + 30;
    while (! is_file($directory.'/go') && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (! is_file($directory.'/go')) {
        throw new LogicException('Race start barrier timed out.');
    }
    $context = new TenantContext($input['organization'], $input['brand'], $input['user'], $input['member']);
    $result = app(TenantContextRunner::class)->run($context, fn () => app(PlanRequestPort::class)->submit(
        $request, new PlanRequestCommand($input['plan'], $input['key'])));
    echo json_encode(['pid' => $pid, 'ticket' => $result->ticketId, 'created' => $result->created,
        'delivery' => $result->deliveryStatus->value, 'fake_mail_count' => Mail::sent(TicketMail::class)->count()], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error).': '.$error->getMessage()."\n");
    exit(1);
}
