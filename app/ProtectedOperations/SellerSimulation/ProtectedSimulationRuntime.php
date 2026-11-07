<?php

namespace App\ProtectedOperations\SellerSimulation;

use App\ProtectedOperations\PostgresTransactionalIdempotency;
use App\ProtectedOperations\SellerFollowup\FollowupCoordinator;
use App\Seller\Engine\LeadState;
use App\Seller\Evaluation\SimulationRuntime;
use App\Seller\Settings\Greetings\GreetingResolver;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/** Trusted job entrypoint to the fake seller follow-up runtime. */
final class ProtectedSimulationRuntime implements SimulationRuntime
{
    public function open(TenantContext $context, string $key): array
    {
        $this->assertContext($context);
        if ($key === '' || strlen($key) > 128) {
            throw new InvalidArgumentException('Invalid simulation key.');
        }

        return app(PostgresTransactionalIdempotency::class)->execute(
            $context->organizationId, $context->brandId, 'seller.simulation.open', $key,
            'simulation-open-v1', function () use ($context): array {
                $conversation = (string) Str::ulid();
                $greeting = app(GreetingResolver::class)->resolve($context, 'api') ?: 'Здравствуйте! Чем могу помочь?';
                DB::table('conversations')->insert([
                    'id' => $conversation, 'organization_id' => $context->organizationId, 'brand_id' => $context->brandId,
                    'mode' => 'bot', 'version' => 1,
                    'state' => json_encode((new LeadState)->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('messages')->insert([
                    'id' => (string) Str::ulid(), 'organization_id' => $context->organizationId,
                    'brand_id' => $context->brandId, 'conversation_id' => $conversation,
                    'role' => 'agent', 'text' => $greeting, 'meta' => json_encode(['fake' => true, 'simulation' => true], JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return ['conversation_id' => $conversation, 'agent' => $greeting];
            }
        );
    }

    public function turn(TenantContext $context, string $conversationId, string $client, string $key): array
    {
        $this->assertContext($context);
        if (! Str::isUlid($conversationId) || trim($client) === '' || mb_strlen($client) > 5000
            || $key === '' || strlen($key) > 128) {
            throw new InvalidArgumentException('Invalid simulation turn.');
        }

        return app(FollowupCoordinator::class)->runJob($context, $conversationId, $client, $key);
    }

    private function assertContext(TenantContext $context): void
    {
        if ($context->source !== 'job' || ! $context->organizationId || ! $context->brandId
            || config('runtime.ai_driver', 'fake') !== 'fake' || DB::connection()->getDriverName() !== 'pgsql'
            || ! DB::table('brands')->where('id', $context->brandId)
                ->where('organization_id', $context->organizationId)->exists()) {
            throw new LogicException('Protected simulation runtime unavailable.');
        }
    }
}
