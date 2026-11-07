<?php

namespace Tests\Feature\SellerRuntime\Turns;

use App\Ai\Drivers\Driver;
use App\Ai\Drivers\FakeDriver;
use App\Seller\Knowledge\Import\YamlKnowledgeImporter;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Database\Seeders\Ai\PromptSetsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

final class ProtectedFollowupFakeDriver implements Driver
{
    public function complete(array $body): array
    {
        return ['content' => isset($body['response_format']) ? '{"client_intent":"answer_only"}' : 'Спасибо за сообщение!',
            'toolCalls' => [], 'usage' => ['promptTokens' => 1, 'completionTokens' => 1],
            'model' => $body['model'], 'latencyMs' => 1, 'costMicros' => 0];
    }
}

trait ProtectedFollowupFixtures
{
    public function refreshDatabase()
    {
        $this->artisan('migrate:fresh');
        RefreshDatabaseState::$migrated = false;
    }

    private function seedProtected(TenantContext $tenant): void
    {
        app(PromptSetsSeeder::class)->run();
        app(TenantContextRunner::class)->run($tenant, fn () => app(YamlKnowledgeImporter::class)
            ->importAndPublish($tenant, base_path('tests/Feature/Seller/Knowledge/Fixtures/data')));
        app()->instance(FakeDriver::class, new ProtectedFollowupFakeDriver);
    }
}
