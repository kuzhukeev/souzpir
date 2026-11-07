<?php

namespace App\Seller\Dialogs;

use App\Ai\CanonicalJson;
use App\Ai\Gateway;
use App\Ai\Protected\ResponderProjection;
use App\Contracts\Ai\AiContext;
use App\Seller\Engine\Policy;
use App\Seller\Engine\ResponderRequest;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Builds the manager-suggestion responder request from the already revalidated extractor result. */
final class ManagerSuggestionResponderInput
{
    public function build(Request $request, string $dialog, string $key): array
    {
        if (DB::transactionLevel() < 1 || config('runtime.ai_driver', 'fake') !== 'fake') {
            throw new LogicException('Manager suggestion unavailable.');
        }
        [, $source, $engine, $state, $message, $knowledge, $history] = app(ManagerSuggestionExtractorInput::class)->build($request, $dialog, $key);
        $extracted = app(Gateway::class)->readManagerSuggestionExtractorLocked($request, $dialog, $key);
        $extraction = $engine->interpretResult($state, $message, $extracted);
        if (($extraction['_meta']['model'] ?? null) === 'fallback') {
            throw new LogicException('Manager suggestion unavailable.');
        }
        $extraction['_text'] = mb_strtolower($message);
        $policy = new Policy($knowledge);
        $policy->applyExtraction($state, $extraction);
        $goal = $policy->decide($state, $extraction, false);
        $tenant = app(TenantContext::class);
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
            throw new LogicException('Manager suggestion unavailable.');
        }
        $system = app(ResponderProjection::class)->render($knowledge, $state, $goal, $name);
        $history[] = ['role' => 'client', 'text' => $message];
        // A manager suggestion is text only; it never authorizes a tool call.
        $input = app(ResponderRequest::class)->build($system, $history, [], $model, (float) $temperature, $prompt->version, $prompt->checksum);
        $context = new AiContext($tenant->organizationId, $tenant->brandId, 'responder', 'seller', 'conversation', $dialog,
            'manager-suggestion-responder-'.hash_hmac('sha256', $key.CanonicalJson::hash($source), (string) config('app.key')),
            ['conversation_content', 'client_profile', 'knowledge_internal']);

        return [$input, $context, $state, $goal, $knowledge];
    }
}
