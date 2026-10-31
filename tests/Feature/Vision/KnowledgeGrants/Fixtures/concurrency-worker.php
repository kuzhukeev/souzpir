<?php

use App\Seller\Knowledge\DatabaseKnowledgeRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\KnowledgeBridge\GrantAuthority;
use App\Vision\KnowledgeGrants\DatabaseGrantAuthority;
use App\Vision\KnowledgeGrants\GrantRegistry;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== config('runtime.test_database')
    || DB::connection()->getDatabaseName() === config('runtime.preview_database')) {
    throw new LogicException('Isolated test database required.');
}
if (! interface_exists(GrantAuthority::class)) {
    require_once __DIR__.'/GrantAuthority.php';
}
$data = json_decode((string) fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
$ctx = new TenantContext($data['org'], $data['brand'], $data['actor'], $data['membership'], 'job');
echo 'ready:'.DB::selectOne('select pg_backend_pid() as pid')->pid."\n";
flush();
fgets(STDIN);
try {
    app(TenantContextRunner::class)->run($ctx, function () use ($ctx, $data): void {
        match ($data['mode']) {
            'gate' => DB::transaction(fn () => app(DatabaseGrantAuthority::class)->assertActive($ctx, $data['grant'], $data['release'], $data['checksum'])),
            'revoke' => app(GrantRegistry::class)->revoke($ctx, $data['grant']),
            'publish' => app(DatabaseKnowledgeRepository::class)->publish($ctx, $data['draft'], 3),
            'rollback' => app(DatabaseKnowledgeRepository::class)->rollback($ctx, $data['target']),
            default => throw new LogicException('Invalid test mode.'),
        };
    });
    echo 'allowed';
} catch (LogicException) {
    echo 'denied';
}
