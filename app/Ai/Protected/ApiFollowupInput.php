<?php

namespace App\Ai\Protected;

use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiGateway;
use App\ProtectedOperations\SellerTurnFence;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Engine\BrandPromptSets;
use App\Seller\Engine\Extractor;
use App\Seller\Engine\ExtractorPromptLoader;
use App\Seller\Engine\LeadState;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Tenancy\TenantContext;
use App\Tenancy\TokenApi\TokenTenantAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        app(TokenTenantAuthority::class)->revalidateLocked($http, $tenant, 'turn');
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
        if (! $lead || ! $conversation || ! $turn || ! str_starts_with($turn->idempotency_key, 'api:') || ! $message || ! in_array($conversation->mode, ['bot', 'suggest'], true)
            || ! is_string($message->text)) {
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
        $context = new AiContext($tenant->organizationId, $tenant->brandId, 'extractor', 'seller', 'conversation',
            $claim->conversationId, 'api-followup-extractor-'.$claim->turnId, ['conversation_content', 'client_profile', 'knowledge_internal']);

        return [$request, $context, $engine, $state, $message->text, $knowledge, $history];
    }
}
