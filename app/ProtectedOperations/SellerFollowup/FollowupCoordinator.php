<?php

namespace App\ProtectedOperations\SellerFollowup;

use App\Contracts\ProtectedOperations\IdempotencyConflict;
use App\Models\Membership;
use App\Models\User;
use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnCommand;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\SessionBrandSelection;
use App\Shell\Organizations\ModuleEntitlements;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Fake-only follow-up and suggestion path; all writes remain in the active session transaction. */
final class FollowupCoordinator
{
    /** Internal fake path for a trusted simulation job; no browser session is involved. */
    public function runJob(TenantContext $tenant, string $conversationId, string $client, string $key): array
    {
        if ($tenant->source !== 'job' || ! $tenant->organizationId || ! $tenant->brandId
            || config('runtime.ai_driver', 'fake') !== 'fake') {
            throw new LogicException('Simulation context unavailable.');
        }

        return app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $conversationId, $client, $key): array {
            $conversation = DB::table('conversations')->where('id', $conversationId)
                ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->where('mode', 'bot')->first(['version']);
            if (! $conversation || (int) $conversation->version < 1
                || DB::connection()->transactionLevel() < 1
                || ! DB::table('messages')->where('conversation_id', $conversationId)
                    ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                    ->where('role', 'agent')->where('meta->simulation', true)->exists()) {
                throw new LogicException('Simulation conversation unavailable.');
            }
            $messageId = (string) Str::ulid();
            DB::table('messages')->insert([
                'id' => $messageId, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
                'conversation_id' => $conversationId, 'role' => 'client', 'text' => $client,
                'meta' => json_encode(['fake' => true, 'simulation' => true], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $claim = app(ConversationRepository::class)->claimTurn(
                $tenant, new TurnCommand($conversationId, $messageId, $client, null, $key, (int) $conversation->version)
            );
            if ($claim->status !== 'claimed') {
                throw new LogicException('Simulation turn unavailable.');
            }
            $outcome = app(FollowupFinalizer::class)->finish($tenant, $claim, 'bot');
            $assistantId = $outcome['result']['message_id'] ?? null;
            $agent = is_string($assistantId)
                ? DB::table('messages')->where('id', $assistantId)->where('organization_id', $tenant->organizationId)
                    ->where('brand_id', $tenant->brandId)->where('conversation_id', $conversationId)->value('text')
                : null;
            if (! is_string($agent) || $agent === '') {
                throw new LogicException('Simulation answer unavailable.');
            }

            return ['agent' => $agent];
        });
    }

    public function run(Request $http, TurnCommand $command, string $mode): array
    {
        if (config('runtime.ai_driver', 'fake') !== 'fake' || ! in_array($mode, ['bot', 'suggest'], true)
            || DB::connection()->getDriverName() !== 'pgsql') {
            return ['status' => 'unavailable'];
        }
        try {
            return app(AuthSessionRegistry::class)->withinActive($http, function (User $user) use ($http, $command, $mode): array {
                $tenant = app(TenantContext::class);
                $selected = app(SessionBrandSelection::class)->current($http);
                if ($tenant->source !== 'session' || $tenant->actorId !== $user->id || ! $user->hasVerifiedEmail()
                    || ! $tenant->organizationId || ! $tenant->brandId || ! $tenant->membershipId
                    || $selected->selected_organization_id !== $tenant->organizationId || $selected->selected_brand_id !== $tenant->brandId
                    || (string) $selected->brand_revision !== $http->attributes->get('tenant.brand_revision')) {
                    throw new LogicException('Context changed.');
                }
                $member = Membership::withoutGlobalScopes()->whereKey($tenant->membershipId)->where('organization_id', $tenant->organizationId)
                    ->where('user_id', $user->id)->where('status', 'active')->first();
                if (! $member || ! $member->permitsBrand($tenant->brandId)
                    || ! app(ModuleEntitlements::class)->enabled($tenant, 'seller')
                    || ! Gate::forUser($user)->allows('seller.dialogues.operate')) {
                    throw new LogicException('Access changed.');
                }
                $conversation = DB::table('conversations')->where('id', $command->conversationId)
                    ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->first(['mode', 'version']);
                if (! $conversation || $conversation->mode !== $mode || ($mode === 'bot' && (int) $conversation->version === 0)) {
                    throw new LogicException('Follow-up unavailable.');
                }
                $claim = app(ConversationRepository::class)->claimTurn($tenant, $command);
                if ($claim->status === 'finished') {
                    $outcome = $claim->outcome;
                    if (! is_array($outcome) || ! in_array($outcome['status'] ?? null, ['completed', 'suggested'], true)) {
                        throw new LogicException('Invalid finished turn.');
                    }

                    $result = ['turn_id' => $claim->turnId, 'message_id' => $outcome['assistantMessageId'] ?? null,
                        'version' => $outcome['version'] ?? null];
                    if ($outcome['status'] === 'suggested') {
                        $result['suggestion'] = $outcome['messages'][0]['text'] ?? null;
                    }

                    return ['status' => $outcome['status'], 'result' => $result];
                }
                if (in_array($claim->status, ['in_progress', 'uncertain'], true)) {
                    return ['status' => $claim->status];
                }
                if ($claim->status !== 'claimed' || $claim->baseVersion !== $command->expectedVersion) {
                    throw new LogicException('Invalid follow-up claim.');
                }

                return app(FollowupFinalizer::class)->finish($tenant, $claim, $mode);
            });
        } catch (HttpExceptionInterface $error) {
            if ($error->getStatusCode() === 409) {
                throw new IdempotencyConflict('Turn key or revision changed.');
            }

            throw $error;
        }
    }
}
