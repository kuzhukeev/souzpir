<?php

namespace App\Seller\Dialogs;

use App\Models\User;
use App\ProtectedOperations\PostgresTransactionalIdempotency;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/** Captures the authorized source for a manager suggestion; it does not generate or send one. */
final class ManagerSuggestionSource
{
    public function capture(Request $request, string $dialog, string $key): array
    {
        abort_unless(Str::isUlid($dialog) && $key !== '' && strlen($key) <= 128, 422);
        abort_unless(config('runtime.ai_driver', 'fake') === 'fake', 503);

        return app(ProfileAccess::class)->run($request, function (User $actor, TenantContext $tenant) use ($request, $dialog, $key): array {
            Gate::forUser($actor)->authorize('seller.dialogues.operate');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);
            $scope = ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId];
            $conversation = DB::table('conversations')->where($scope)->where('id', $dialog)->lockForUpdate()->first();
            abort_unless($conversation && $conversation->mode === 'manager' && $conversation->assigned_user_id === $actor->id, 404);
            abort_unless($conversation->history_ready === true && ! in_array($conversation->stage, ['done', 'lost', 'spam'], true), 409);
            $latest = DB::table('messages')->where($scope)->where('conversation_id', $dialog)->orderByDesc('history_sequence')->first(['id', 'role', 'history_sequence']);
            abort_unless($latest && $latest->role === 'client' && (string) $latest->history_sequence === (string) $conversation->history_sequence, 409);
            $knowledge = app(KnowledgeRepository::class)->published($tenant);
            $source = ['actor_id' => $actor->id, 'session_hash' => hash_hmac('sha256', 'manager-suggestion:'.$request->session()->getId(), (string) config('app.key')),
                'selection_revision' => $request->attributes->get('profile.brand_revision'), 'conversation_id' => $dialog, 'message_id' => $latest->id,
                'version' => (int) $conversation->version, 'history_revision' => (int) $conversation->history_revision,
                'history_sequence' => (int) $conversation->history_sequence, 'release_id' => $knowledge->releaseId(),
                'release_checksum' => $knowledge->checksum()];

            return app(PostgresTransactionalIdempotency::class)->execute($tenant->organizationId, $tenant->brandId,
                'seller.manager_suggestion.source', $key, json_encode($source, JSON_THROW_ON_ERROR), fn () => $source);
        });
    }
}
