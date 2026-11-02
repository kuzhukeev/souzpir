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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Fake-only follow-up and suggestion path; all writes remain in the active session transaction. */
final class FollowupCoordinator
{
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

                    return ['status' => $outcome['status'], 'result' => ['turn_id' => $claim->turnId,
                        'message_id' => $outcome['assistantMessageId'] ?? null, 'version' => $outcome['version'] ?? null,
                        'suggestion' => $outcome['status'] === 'suggested' ? ($outcome['messages'][0]['text'] ?? null) : null]];
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
