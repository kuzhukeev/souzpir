<?php

use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\KnowledgeGrants\GrantRegistry;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

try {
    require __DIR__.'/../../../../../vendor/autoload.php';
    $app = require __DIR__.'/../../../../../bootstrap/app.php';
    $app->loadEnvironmentFrom('.env.testing');
    $app->make(Kernel::class)->bootstrap();
    [$script, $organization, $brand, $owner, $member, $grant, $expectedBase, $expectedDatabase] = $argv;
    if ($app->basePath() !== $expectedBase || DB::connection()->getDatabaseName() !== $expectedDatabase
        || $expectedDatabase !== 'souzpir_test_sp_e8_01' || $expectedDatabase !== config('runtime.test_database')
        || $expectedDatabase === config('runtime.preview_database')) {
        throw new RuntimeException('Unexpected test runtime.');
    }
    $context = new TenantContext($organization, $brand, $owner, $member);
    $revoked = app(TenantContextRunner::class)->run($context, fn () => app(GrantRegistry::class)->revoke($context, $grant));
    if (! $revoked || DB::table('vision_knowledge_grants')->where('id', $grant)->whereNotNull('revoked_at')->doesntExist()) {
        throw new RuntimeException('Revocation was not persisted.');
    }
    fwrite(STDOUT, "REVOCATION_OK\n");
} catch (Throwable $exception) {
    $code = (string) $exception->getCode();
    $sqlstate = preg_match('/^[0-9A-Z]{5}$/', $code) ? $code : 'none';
    fwrite(STDERR, 'REVOKE_FAILED '.get_class($exception).' SQLSTATE='.$sqlstate."\n");
    exit(1);
}
