<?php

namespace App\Seller\Dialogs;

use App\Contracts\Ai\AiGateway;
use App\Models\User;
use App\Seller\Engine\BrandPromptSets;
use App\Seller\Engine\Extractor;
use App\Seller\Engine\ExtractorPromptLoader;
use App\Seller\Engine\LeadState;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Builds an internal extractor input from a revalidated manager source, never from HTTP text. */
final class ManagerSuggestionExtractorInput
{
    public function build(Request $request, string $dialog, string $key): array
    {
        return app(ProfileAccess::class)->run($request, function (User $actor, TenantContext $tenant) use ($request, $dialog, $key): array {
            $source = app(ManagerSuggestionSource::class)->capture($request, $dialog, $key);
            $scope = ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId, 'conversation_id' => $dialog];
            $conversation = DB::table('conversations')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('id', $dialog)->first();
            $message = DB::table('messages')->where($scope)->where('id', $source['message_id'])->where('role', 'client')->first(['text']);
            $stateData = $conversation ? json_decode($conversation->state, true, 512, JSON_THROW_ON_ERROR) : null;
            if (! is_array($stateData) || ! $message || ! is_string($message->text)) {
                throw new LogicException('Manager suggestion input unavailable.');
            }
            $history = DB::table('messages')->where($scope)->whereIn('role', ['client', 'agent'])->where('history_sequence', '<', $source['history_sequence'])
                ->orderByDesc('history_sequence')->limit(6)->get(['role', 'text'])->reverse()->values()->map(fn ($row) => ['role' => $row->role, 'text' => $row->text])->all();
            $knowledge = app(KnowledgeRepository::class)->published($tenant);
            if ($source['release_id'] !== $knowledge->releaseId() || ! hash_equals($source['release_checksum'], $knowledge->checksum())) {
                throw new LogicException('Manager suggestion knowledge changed.');
            }
            $name = $knowledge->persona()['name'] ?? null;
            $model = config('features.E0-05.defaults.seller_defaults.extractor.model');
            $temperature = config('features.E0-05.defaults.seller_defaults.extractor.temperature');
            if (! is_string($name) || trim($name) === '' || ! is_string($model) || $model === '' || ! is_numeric($temperature)) {
                throw new LogicException('Manager suggestion extractor unavailable.');
            }
            $row = app(BrandPromptSets::class)->load($tenant, $knowledge, 'extractor');
            $prompt = app(ExtractorPromptLoader::class)->load($tenant, $row->version);
            $engine = new Extractor(app(AiGateway::class), $knowledge, $prompt, $model, (float) $temperature, $name);
            $state = new LeadState($stateData);

            return [$engine->buildRequest($state, $history, $message->text), $source, $engine, $state, $message->text, $knowledge, $history];
        });
    }
}
