<?php

namespace App\Ai\Protected;

use App\Ai\CanonicalJson;
use App\Ai\ContactMasker;
use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiRequest;
use App\Models\Membership;
use App\Models\User;
use App\ProtectedOperations\SellerTurnFence;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Engine\SummaryProjection;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\SessionBrandSelection;
use App\Shell\Organizations\ModuleEntitlements;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LogicException;
use SensitiveParameter;
use Throwable;

/** A narrowly scoped summary-stage source; it is not a Seller finalizer. */
final class SellerSummaryStages
{
    public function prepare(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim): string
    {
        try {
            return $this->authorized($http, $claim, function (TenantContext $tenant, User $user, object $selection) use ($http, $claim): string {
                $existing = DB::table('c02_ai_stages')->where('turn_id', $claim->turnId)->lockForUpdate()->first();
                if ($existing) {
                    $this->verify($existing, $http, $claim, $tenant, $user, $selection);

                    return $existing->id;
                }
                $turn = DB::table('seller_turns')->where('id', $claim->turnId)->first();
                $conversation = DB::table('conversations')->where('id', $claim->conversationId)->first();
                $history = DB::table('messages')->where('conversation_id', $claim->conversationId)
                    ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                    ->whereIn('role', ['client', 'agent'])->where('history_sequence', '<=', $turn->cutoff_sequence)
                    ->orderBy('history_sequence')->get(['role', 'text'])->map(fn ($m) => ['role' => $m->role, 'text' => $m->text])->all();
                $prompt = $this->publishedPrompt();
                $state = json_decode($conversation->state, true, 512, JSON_THROW_ON_ERROR);
                $previous = is_array($state) ? ($state['summary'] ?? null) : null;
                if ($previous !== null && ! is_string($previous)) {
                    throw new LogicException;
                }
                $model = config('features.E0-05.defaults.seller_defaults.summary.model');
                if (! is_string($model) || $model === '') {
                    throw new LogicException;
                }
                $rendered = Blade::render($prompt->body, ['previous' => $previous], deleteCachedView: true);
                $request = app(SummaryProjection::class)->request($rendered, $history, $model, $prompt->version, $prompt->checksum);
                $json = json_encode($request->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $id = (string) Str::ulid();
                $data = [
                    'id' => $id, 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
                    'conversation_id' => $claim->conversationId, 'turn_id' => $claim->turnId, 'actor_id' => $user->id,
                    'session_hash' => $this->sessionHash($http), 'selection_revision' => (int) $selection->brand_revision,
                    'history_revision' => (int) $turn->base_history_revision,
                    'cutoff_sequence' => (int) $turn->cutoff_sequence, 'base_version' => $claim->baseVersion,
                    'request_encrypted' => Crypt::encryptString($json), 'request_digest' => $this->digest($json),
                ];
                $data['seal'] = $this->seal((object) $data);
                DB::table('c02_ai_stages')->insert($data);

                return $id;
            });
        } catch (Throwable) {
            throw new LogicException('Protected summary stage rejected.');
        }
    }

    /** A stage ID identifies a row; session, tenant, rights and claim are rechecked. */
    public function load(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim, #[SensitiveParameter] string $stageId): array
    {
        try {
            return $this->authorized($http, $claim, function (TenantContext $tenant, User $user, object $selection) use ($http, $claim, $stageId): array {
                $row = DB::table('c02_ai_stages')->where('id', $stageId)->lockForUpdate()->first();
                if (! $row) {
                    throw new LogicException;
                }
                $request = $this->verify($row, $http, $claim, $tenant, $user, $selection);
                $context = new AiContext($row->organization_id, $row->brand_id, 'summary', 'seller', 'conversation', $row->conversation_id, 'c02-summary-'.$row->id, ['conversation_content']);

                return [$request, $context, $row->request_digest, $row->ai_call_id, $row->gateway_request_hash];
            });
        } catch (Throwable) {
            throw new LogicException('Protected summary stage rejected.');
        }
    }

    /** Called only by Gateway during its reservation transaction, before driver I/O. */
    public function lockForGateway(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim, #[SensitiveParameter] string $stageId, #[SensitiveParameter] string $expectedDigest): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Protected summary stage rejected.');
        }
        [, , $digest] = $this->load($http, $claim, $stageId);
        if (! hash_equals($digest, $expectedDigest)) {
            throw new LogicException('Protected summary stage rejected.');
        }
    }

    /** The call row already exists in the same reservation transaction. */
    public function bindCall(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim, #[SensitiveParameter] string $stageId, #[SensitiveParameter] object $call, #[SensitiveParameter] string $requestHash): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Protected summary stage rejected.');
        }
        [$request, $context] = $this->load($http, $claim, $stageId);
        $body = app(ContactMasker::class)->request($request->toArray());
        $expected = app(RequestFingerprint::class)->hash($request, $context, $body, config('runtime.ai_driver', 'fake'));
        if (! hash_equals($expected, $requestHash) || ! hash_equals((string) $call->request_hash, $expected)) {
            throw new LogicException('Protected summary stage rejected.');
        }
        $stage = DB::table('c02_ai_stages')->where('id', $stageId)->lockForUpdate()->first();
        if (! $stage || ! hash_equals((string) $call->idempotency_key_hash, hash_hmac('sha256', 'c02-summary-'.$stageId, (string) config('app.key')))) {
            throw new LogicException('Protected summary stage rejected.');
        }
        if ($stage->ai_call_id !== null) {
            if (! hash_equals($stage->ai_call_id, $call->id) || ! hash_equals($stage->gateway_request_hash, $requestHash)) {
                throw new LogicException('Protected summary stage rejected.');
            }

            return;
        }
        DB::table('c02_ai_stages')->where('id', $stageId)->whereNull('ai_call_id')->update(['ai_call_id' => $call->id, 'gateway_request_hash' => $requestHash]);
    }

    private function authorized(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim, #[SensitiveParameter] \Closure $action): mixed
    {
        return app(AuthSessionRegistry::class)->withinActive($http, function (User $user) use ($http, $claim, $action): mixed {
            $tenant = app(TenantContext::class);
            $selection = app(SessionBrandSelection::class)->current($http);
            if ($tenant->source !== 'session' || $tenant->actorId !== $user->id || ! $tenant->membershipId
                || $selection->selected_organization_id !== $tenant->organizationId || $selection->selected_brand_id !== $tenant->brandId
                || $claim->organizationId !== $tenant->organizationId || $claim->brandId !== $tenant->brandId) {
                throw new LogicException;
            }
            app(SellerTurnFence::class)->assertCurrent($tenant, $claim);
            $member = Membership::withoutGlobalScopes()->whereKey($tenant->membershipId)->where('organization_id', $tenant->organizationId)
                ->where('user_id', $user->id)->where('status', 'active')->lockForUpdate()->first();
            $module = DB::table('brand_modules')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->where('module', 'seller')->lockForUpdate()->first();
            if (! $member || ! $member->permitsBrand($tenant->brandId) || ! $module || ! $module->enabled
                || ! app(ModuleEntitlements::class)->enabled($tenant, 'seller') || ! Gate::forUser($user)->allows('seller.dialogues.operate')) {
                throw new LogicException;
            }

            return $action($tenant, $user, $selection);
        });
    }

    private function verify(#[SensitiveParameter] object $row, #[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim, #[SensitiveParameter] TenantContext $tenant, #[SensitiveParameter] User $user, #[SensitiveParameter] object $selection): AiRequest
    {
        if ($row->organization_id !== $tenant->organizationId || $row->brand_id !== $tenant->brandId
            || $row->conversation_id !== $claim->conversationId || $row->turn_id !== $claim->turnId
            || $row->actor_id !== $user->id || ! hash_equals($row->session_hash, $this->sessionHash($http))
            || (string) $row->selection_revision !== (string) $selection->brand_revision
            || (int) $row->base_version !== $claim->baseVersion || ! hash_equals($row->seal, $this->seal($row))) {
            throw new LogicException;
        }
        $turn = DB::table('seller_turns')->where('id', $claim->turnId)->first();
        if ((string) $row->history_revision !== (string) $turn->base_history_revision || (string) $row->cutoff_sequence !== (string) $turn->cutoff_sequence) {
            throw new LogicException;
        }
        $json = Crypt::decryptString($row->request_encrypted);
        if (! hash_equals($row->request_digest, $this->digest($json))) {
            throw new LogicException;
        }
        $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $prompt = $this->publishedPrompt();
        if (($value['prompt_version'] ?? null) !== $prompt->version || ($value['prompt_checksum'] ?? null) !== $prompt->checksum
            || ($value['model'] ?? null) !== config('features.E0-05.defaults.seller_defaults.summary.model')) {
            throw new LogicException;
        }

        return new AiRequest($value['messages'], $value['model'], $value['temperature'], maxTokens: $value['max_tokens'], promptVersion: $value['prompt_version'], promptChecksum: $value['prompt_checksum']);
    }

    private function publishedPrompt(): object
    {
        $prompt = DB::table('prompt_sets')->whereNull('organization_id')->whereNull('brand_id')
            ->where('name', 'summary')->where('version', 'v1')->where('status', 'published')->lockForUpdate()->first();
        $body = file_get_contents(resource_path('views/prompts/summary.blade.php'));
        if (! $prompt || ! is_string($body) || ! hash_equals($prompt->checksum, hash('sha256', $body)) || $prompt->body !== $body) {
            throw new LogicException;
        }

        return $prompt;
    }

    private function sessionHash(#[SensitiveParameter] Request $http): string
    {
        return hash_hmac('sha256', 'c02-ai-session:'.$http->session()->getId(), (string) config('app.key'));
    }

    private function digest(#[SensitiveParameter] string $json): string
    {
        return hash_hmac('sha256', 'c02-ai-request:'.$json, (string) config('app.key'));
    }

    private function seal(#[SensitiveParameter] object $row): string
    {
        $identity = [];
        foreach (['id', 'organization_id', 'brand_id', 'conversation_id', 'turn_id', 'actor_id', 'session_hash', 'selection_revision', 'history_revision', 'cutoff_sequence', 'base_version', 'request_digest'] as $field) {
            $identity[$field] = (string) $row->{$field};
        }

        return hash_hmac('sha256', 'c02-ai-stage:'.CanonicalJson::hash($identity), (string) config('app.key'));
    }
}
