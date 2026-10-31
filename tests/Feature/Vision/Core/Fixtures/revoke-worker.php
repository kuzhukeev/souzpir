<?php

use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\KnowledgeGrants\GrantRegistry;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

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
app(TenantContextRunner::class)->run($context, fn () => app(GrantRegistry::class)->revoke($context, $grant));
