<?php

use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiGateway;
use App\Seller\Engine\Extractor;
use App\Seller\Engine\ExtractorPromptLoader;
use App\Seller\Engine\LeadState;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $org, $brand, $conversation, $release, $database] = $argv;
if (! $app->environment('testing') || $app->basePath() !== getcwd() || config('database.connections.pgsql.database') !== $database || env('DB_TEST_DATABASE') !== $database || env('APP_PREVIEW_DATABASE') === $database || ini_get('zend.exception_ignore_args') !== '0') {
    throw new RuntimeException('Isolated test with full exception traces required.');
}
config(['runtime.ai_driver' => 'fake']);
$tenant = new TenantContext($org, $brand, source: 'job');
try {
    app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $org, $brand, $conversation, $release): void {
        $snapshot = app(KnowledgeRepository::class)->forRelease($tenant, $release, false);
        $prompt = app(ExtractorPromptLoader::class)->load($tenant);
        $engine = new Extractor(app(AiGateway::class), $snapshot, $prompt, 'google/gemini-2.5-flash', 0.0, 'Anton');
        $context = new AiContext($org, $brand, 'extractor', 'seller', 'conversation', $conversation, 'privacy-trace-key', ['conversation_content', 'client_profile', 'knowledge_internal']);
        $engine->extract(new LeadState(['slots' => ['email' => 'state-secret@example.test']]), [['role' => 'client', 'text' => 'history-secret@example.test']], 'trace-user at example.test', $context);
    });
    throw new RuntimeException('Privacy boundary did not reject input.');
} catch (InvalidArgumentException $error) {
    $trace = (string) $error.json_encode($error->getTrace(), JSON_THROW_ON_ERROR);
    foreach (['state-secret', 'history-secret', 'trace-user', 'example.test'] as $secret) {
        if (str_contains($trace, $secret)) {
            throw new RuntimeException('Sensitive input leaked in exception trace.');
        }
    }
    if ($error->getPrevious() !== null) {
        throw new RuntimeException('Sensitive exception was chained.');
    }
    echo 'redacted';
}
