<?php

namespace App\Ai\Protected;

use App\Ai\Gateway;
use App\Contracts\Ai\AiGateway;
use App\Models\Membership;
use App\Models\User;
use App\ProtectedOperations\SellerTurnFence;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Engine\BrandPromptSets;
use App\Seller\Engine\DeterministicGuard;
use App\Seller\Engine\Extractor;
use App\Seller\Engine\ExtractorPromptLoader;
use App\Seller\Engine\GuardDecision;
use App\Seller\Engine\GuardRequest;
use App\Seller\Engine\LeadState;
use App\Seller\Engine\Policy;
use App\Seller\Engine\ResponderRequest;
use App\Seller\Engine\ResponderResult;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Runtime\BrandRuntimeSchedule;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\SessionBrandSelection;
use App\Shell\Organizations\ModuleEntitlements;
use App\Tenancy\TenantContext;
use App\Tenancy\TokenApi\TokenTenantAuthority;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

/** Build extractor input from an authorized, persisted API turn. */
final class ApiFollowupInput
{
    public function extractorLocked(Request $http, TenantContext $tenant, TurnClaim $claim): array
    {
        if (DB::transactionLevel() < 1 || config('runtime.ai_driver', 'fake') !== 'fake'
            || ! $tenant->organizationId || ! $tenant->brandId) {
            throw new LogicException('API follow-up unavailable.');
        }
        $isToken = $tenant->source === 'token';
        $isWidget = $tenant->source === 'public';
        if ($isToken) {
            app(TokenTenantAuthority::class)->revalidateLocked($http, $tenant, 'turn');
        } elseif ($isWidget) {
            $widgetSession = DB::table('widget_sessions')->where('organization_id', $tenant->organizationId)
                ->where('brand_id', $tenant->brandId)->where('conversation_id', $claim->conversationId)->lockForUpdate()->first();
            if (! $widgetSession || $widgetSession->expires_at <= now()) {
                throw new LogicException('API follow-up unavailable.');
            }
        } elseif ($tenant->source === 'session') {
            app(AuthSessionRegistry::class)->withinActive($http, function (User $user) use ($http, $tenant): void {
                $selected = app(SessionBrandSelection::class)->current($http);
                $member = Membership::withoutGlobalScopes()->whereKey($tenant->membershipId)->where('organization_id', $tenant->organizationId)
                    ->where('user_id', $user->id)->where('status', 'active')->lockForUpdate()->first();
                if ($tenant->actorId !== $user->id || ! $user->hasVerifiedEmail() || ! $member || ! $member->permitsBrand($tenant->brandId)
                    || $selected->selected_organization_id !== $tenant->organizationId || $selected->selected_brand_id !== $tenant->brandId
                    || (string) $selected->brand_revision !== $http->attributes->get('tenant.brand_revision')
                    || ! app(ModuleEntitlements::class)->enabled($tenant, 'seller') || ! Gate::forUser($user)->allows('seller.dialogues.operate')) {
                    throw new LogicException('Seller follow-up unavailable.');
                }
            });
        } elseif ($tenant->source !== 'job') {
            throw new LogicException('Seller follow-up unavailable.');
        }
        if (! app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId)) {
            throw new LogicException('API follow-up unavailable.');
        }
        app(SellerTurnFence::class)->assertCurrent($tenant, $claim);
        $scope = ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId];
        $lead = DB::table('seller_api_lead_requests')->where($scope)->where('conversation_id', $claim->conversationId)->first();
        $conversation = DB::table('conversations')->where($scope)->where('id', $claim->conversationId)->lockForUpdate()->first();
        $turn = DB::table('seller_turns')->where($scope)->where('id', $claim->turnId)->lockForUpdate()->first();
        $message = $turn ? DB::table('messages')->where($scope)->where('conversation_id', $claim->conversationId)
            ->where('id', $turn->input_message_id)->where('role', 'client')->first() : null;
        $simulation = $tenant->source === 'job';
        $validSimulation = ! $simulation || ($turn && str_starts_with($turn->idempotency_key, 'sim:')
            && DB::table('messages')->where($scope)->where('conversation_id', $claim->conversationId)
                ->where('role', 'agent')->where('meta->simulation', true)->exists()
            && DB::table('messages')->where($scope)->where('id', $turn->input_message_id)
                ->where('conversation_id', $claim->conversationId)->where('meta->simulation', true)->exists());
        if (! $validSimulation || ! $conversation || ! $turn || ($isToken && (! $lead || ! str_starts_with($turn->idempotency_key, 'api:')))
            || ($isWidget && ! str_starts_with($turn->idempotency_key, 'widget:')) || ! $message || ! in_array($conversation->mode, ['bot', 'suggest'], true)
            || ! is_string($message->text)) {
            throw new LogicException('API follow-up unavailable.');
        }
        $schedule = app(BrandRuntimeSchedule::class);
        $scheduleMode = $schedule->mode($tenant, $schedule->settings($tenant), CarbonImmutable::now());
        if ($scheduleMode === 'off' || ($scheduleMode === 'suggest' && $conversation->mode === 'bot')) {
            throw new LogicException('API follow-up unavailable.');
        }
        $stateData = json_decode($conversation->state, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($stateData)) {
            throw new LogicException('API follow-up unavailable.');
        }
        $state = new LeadState($stateData);
        $history = DB::table('messages')->where($scope)->where('conversation_id', $claim->conversationId)
            ->whereIn('role', ['client', 'agent'])->where('history_sequence', '<', $turn->cutoff_sequence)
            ->orderByDesc('history_sequence')->limit(6)->get(['role', 'text'])->reverse()->values()
            ->map(fn ($row) => ['role' => $row->role, 'text' => $row->text])->all();
        $knowledge = app(KnowledgeRepository::class)->published($tenant);
        $name = $knowledge->persona()['name'] ?? null;
        $promptRow = app(BrandPromptSets::class)->load($tenant, $knowledge, 'extractor');
        $prompt = app(ExtractorPromptLoader::class)->load($tenant, $promptRow->version);
        $model = config('features.E0-05.defaults.seller_defaults.extractor.model');
        $temperature = config('features.E0-05.defaults.seller_defaults.extractor.temperature');
        if (! is_string($name) || trim($name) === '' || ! is_string($model) || $model === '' || ! is_numeric($temperature)) {
            throw new LogicException('API follow-up unavailable.');
        }
        $engine = new Extractor(app(AiGateway::class), $knowledge, $prompt, $model, (float) $temperature, $name);
        $request = $engine->buildRequest($state, $history, $message->text);
        $context = app(ProtectedRetryContext::class)->make($tenant, $claim->conversationId, 'extractor', 'api-followup-extractor-'.$claim->turnId);

        return [$request, $context, $engine, $state, $message->text, $knowledge, $history];
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolResults  results of a previous tool round; an empty array offers tools again
     * @param  list<string>  $guardIssues  deterministic/model guard issues from a previous rejected round, fed back for regeneration
     */
    public function responderLocked(Request $http, TenantContext $tenant, TurnClaim $claim, int $round = 0,
        array $toolResults = [], array $guardIssues = []): array
    {
        if ($round < 0 || $round > 2) {
            throw new LogicException('API follow-up unavailable.');
        }
        [, , $extractor, $state, $message, $knowledge, $history] = $this->extractorLocked($http, $tenant, $claim);
        $result = app(Gateway::class)->readApiFollowupExtractorLocked($http, $tenant, $claim);
        $extraction = $extractor->interpretResult($state, $message, $result);
        if (($extraction['_meta']['model'] ?? null) === 'fallback') {
            throw new LogicException('API follow-up unavailable.');
        }
        $extraction['_text'] = mb_strtolower($message);
        $policy = new Policy($knowledge);
        $policy->applyExtraction($state, $extraction);
        $goal = $policy->decide($state, $extraction, false);
        $name = $knowledge->persona()['name'] ?? null;
        $prompt = DB::table('prompt_sets')->where('name', 'responder')->where('version', 'v1')->where('status', 'published')
            ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->first();
        $prompt ??= DB::table('prompt_sets')->where('name', 'responder')->where('version', 'v1')->where('status', 'published')
            ->whereNull('organization_id')->whereNull('brand_id')->first();
        $trusted = file_get_contents(resource_path('views/prompts/responder.blade.php'));
        $model = config('features.E0-05.defaults.seller_defaults.responder.model');
        $temperature = config('features.E0-05.defaults.seller_defaults.responder.temperature');
        if ($goal->kind === 'silent' || ! is_string($name) || trim($name) === '' || ! $prompt
            || ! is_string($trusted) || ! hash_equals(hash('sha256', $prompt->body), $prompt->checksum)
            || ! hash_equals(hash('sha256', $trusted), $prompt->checksum) || ! is_string($model) || ! is_numeric($temperature)) {
            throw new LogicException('API follow-up unavailable.');
        }
        $system = app(ResponderProjection::class)->render($knowledge, $state, $goal, $name);
        $history[] = ['role' => 'client', 'text' => $message];
        $input = app(ResponderRequest::class)->build($system, $history, $toolResults === [] ? $goal->allowedTools : [],
            $model, (float) $temperature, $prompt->version, $prompt->checksum, $toolResults, $guardIssues);
        $context = app(ProtectedRetryContext::class)->make($tenant, $claim->conversationId, 'responder',
            'api-followup-responder-'.$claim->turnId.'-'.$round);

        return [$input, $context, $state, $goal, $knowledge, $extraction];
    }

    /** Build a guard request solely from the authorized, stored responder result of the given round. */
    public function guardLocked(Request $http, TenantContext $tenant, TurnClaim $claim, int $round = 0,
        array $toolResults = [], array $guardIssues = []): ?array
    {
        if (! config('agent.guard_llm', false)) {
            return null;
        }
        [, , $state, $goal, $knowledge] = $this->responderLocked($http, $tenant, $claim, $round, $toolResults, $guardIssues);
        $candidate = app(ResponderResult::class)->interpret(app(Gateway::class)->readApiFollowupResponderLocked($http, $tenant, $claim, $round, $toolResults, $guardIssues));
        if (($candidate['status'] ?? null) !== 'candidate') {
            throw new LogicException('API follow-up unavailable.');
        }
        $deterministic = (new DeterministicGuard($knowledge))->check($candidate['text'], $goal, $state);
        if (! app(GuardDecision::class)->requiresModel($deterministic, $goal, true)) {
            return null;
        }
        $prompt = app(BrandPromptSets::class)->load($tenant, $knowledge, 'guard');
        $model = config('features.E0-05.defaults.seller_defaults.guard.model');
        if (! $prompt || ! hash_equals(hash('sha256', $prompt->body), $prompt->checksum) || ! is_string($model) || $model === '') {
            throw new LogicException('API follow-up unavailable.');
        }
        $projection = app(GuardProjection::class)->render($state, $goal, $prompt->body);
        if (! hash_equals($projection['checksum'], $prompt->checksum)) {
            throw new LogicException('API follow-up unavailable.');
        }
        $input = app(GuardRequest::class)->build($projection['body'], $candidate['text'], $model, $prompt->version, $prompt->checksum);
        $context = app(ProtectedRetryContext::class)->make($tenant, $claim->conversationId, 'guard',
            'api-followup-guard-'.$claim->turnId.'-'.$round);

        return [$input, $context, $deterministic, $goal];
    }
}
