<?php

namespace App\Seller\Runtime;

use App\Contracts\ProtectedOperations\IdempotencyConflict;
use App\Models\Membership;
use App\Models\User;
use App\ProtectedOperations\FirstTurnCoordinator;
use App\Seller\Conversations\Data\TurnCommand;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\SessionBrandSelection;
use App\Shell\Organizations\ModuleEntitlements;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

/** First inbound fake-only runtime; subsequent turns and delivery remain separate. */
final class FirstInboundRuntime
{
    public function turn(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnCommand $command): array
    {
        try {
            if (config('runtime.ai_driver', 'fake') !== 'fake' || ! Str::isUlid($command->conversationId)
                || ! Str::isUlid($command->inputMessageId) || $command->idempotencyKey === '' || strlen($command->idempotencyKey) > 512) {
                return ['status' => 'unavailable'];
            }
            $mode = app(AuthSessionRegistry::class)->withinActive($http, function (User $user) use ($http, $command): string {
                $tenant = app(TenantContext::class);
                $selection = app(SessionBrandSelection::class)->current($http);
                $revision = $http->attributes->get('tenant.brand_revision');
                if ($tenant->source !== 'session' || $tenant->actorId !== $user->id || ! $user->hasVerifiedEmail()
                    || ! $tenant->organizationId || ! $tenant->brandId || ! $tenant->membershipId
                    || ! is_string($revision) || $revision !== (string) $selection->brand_revision
                    || $selection->selected_organization_id !== $tenant->organizationId || $selection->selected_brand_id !== $tenant->brandId) {
                    throw new \LogicException;
                }
                $member = Membership::withoutGlobalScopes()->whereKey($tenant->membershipId)->where('organization_id', $tenant->organizationId)
                    ->where('user_id', $user->id)->where('status', 'active')->first();
                if (! $member || ! $member->permitsBrand($tenant->brandId)
                    || ! app(ModuleEntitlements::class)->enabled($tenant, 'seller') || ! Gate::forUser($user)->allows('seller.dialogues.operate')) {
                    throw new \LogicException;
                }
                $conversation = DB::table('conversations')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                    ->where('id', $command->conversationId)->first(['id', 'mode', 'version']);
                $message = DB::table('messages')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                    ->where('conversation_id', $command->conversationId)->where('id', $command->inputMessageId)->where('role', 'client')
                    ->first(['text', 'external_event_id']);
                if (! $conversation || ! $message || ! hash_equals($message->text, $command->text)
                    || $message->external_event_id !== $command->externalEventId) {
                    throw new \LogicException;
                }
                if ($conversation->mode === 'manager' && $command->expectedVersion !== (int) $conversation->version) {
                    throw new \LogicException;
                }

                return $conversation->mode;
            });
            if ($mode === 'manager') {
                return ['status' => 'suppressed'];
            }
            if ($mode !== 'bot') {
                return ['status' => 'unavailable'];
            }

            return app(FirstTurnCoordinator::class)->run($http, $command);
        } catch (IdempotencyConflict) {
            throw new IdempotencyConflict('Idempotency key was used with different content.');
        } catch (Throwable) {
            return ['status' => 'unavailable'];
        }
    }
}
