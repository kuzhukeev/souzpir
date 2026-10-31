<?php

use App\Ai\Drivers\Driver;
use App\Ai\Drivers\FakeDriver;
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
if (! $app->environment('testing') || $app->basePath() !== getcwd() || ! isset($argv[7]) || config('database.connections.pgsql.database') !== $argv[7] || env('DB_TEST_DATABASE') !== $argv[7] || env('APP_PREVIEW_DATABASE') === $argv[7]) {
    throw new RuntimeException('Isolated test environment required.');
}
[$script, $marker, $releaseFile, $org, $brand, $conversation, $release] = $argv;
$driver = new class($marker, $releaseFile) implements Driver
{
    public function __construct(private string $marker, private string $release) {}

    public function complete(array $body): array
    {
        file_put_contents($this->marker, 'started');
        $deadline = microtime(true) + 20;
        while (! file_exists($this->release)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Test barrier timed out.');
            }
            usleep(10000);
        }

        return ['content' => '{"client_intent":"answer_only","slot_updates":[]}', 'toolCalls' => [], 'usage' => ['promptTokens' => 1, 'completionTokens' => 1], 'model' => $body['model'], 'latencyMs' => 1, 'costMicros' => 0];
    }
};
$app->instance(FakeDriver::class, $driver);
config(['runtime.ai_driver' => 'fake']);
$tenant = new TenantContext($org, $brand, source: 'job');
try {
    app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $org, $brand, $conversation, $release): void {
        $snapshot = app(KnowledgeRepository::class)->forRelease($tenant, $release, false);
        $prompt = app(ExtractorPromptLoader::class)->load($tenant);
        $engine = new Extractor(app(AiGateway::class), $snapshot, $prompt, 'google/gemini-2.5-flash', 0.0, 'Anton');
        $context = new AiContext($org, $brand, 'extractor', 'seller', 'conversation', $conversation, 'race-key', ['conversation_content', 'client_profile', 'knowledge_internal']);
        $result = $engine->extract(new LeadState, [], 'Race message', $context);
        echo $result['client_intent'];
    });
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage());
    exit(1);
}
