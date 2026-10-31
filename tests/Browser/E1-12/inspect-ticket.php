<?php

declare(strict_types=1);

// This helper reports counts only, and refuses to connect outside the dedicated fixture.
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$base = realpath($app->basePath());
$expectedBase = realpath(__DIR__.'/../../..');
$connection = Illuminate\Support\Facades\DB::connection();
if ($base === false || $expectedBase === false || $base !== $expectedBase
    || basename($base) !== 'sp-e1-12' || basename(dirname($base)) !== '.worktrees'
    || ! is_file($base.DIRECTORY_SEPARATOR.'.git')
    || ! $app->environment('testing')
    || getenv('RUN_SUPPORT_PRIVACY_E2E') !== '1'
    || getenv('E112_FIXTURE_ENABLED') !== '1'
    || config('database.default') !== 'pgsql'
    || $connection->getDatabaseName() !== 'souzpir_test_sp_e1_12'
    || config('database.connections.pgsql.host') !== '127.0.1.48'
    || $connection->getDatabaseName() !== config('runtime.test_database')
    || $connection->getDatabaseName() === config('runtime.preview_database')
    || getenv('E112_FIXTURE_ORG') !== '01J00000000000000000000112'
    || app(App\Tenancy\Cabinet::class)->organizationId() !== '01J00000000000000000000112') {
    fwrite(STDERR, "Refusing to inspect a non-fixture environment.\n");
    exit(1);
}

[$script, $submissionKey, $ticketId] = $argv + [null, '', ''];
if (! Illuminate\Support\Str::isUlid($submissionKey) || ! Illuminate\Support\Str::isUlid($ticketId)) {
    fwrite(STDERR, "Invalid synthetic ticket identity.\n");
    exit(1);
}

$query = Illuminate\Support\Facades\DB::table('tickets')
    ->where('organization_id', '01J00000000000000000000112')
    ->where('submission_key', $submissionKey);
$records = $query->get(['id', 'delivery_status']);
echo json_encode([
    'count' => $records->count(),
    'matching_ticket' => $records->where('id', $ticketId)->count(),
    'delivery_status' => $records->count() === 1 ? $records->first()->delivery_status : null,
], JSON_THROW_ON_ERROR);
