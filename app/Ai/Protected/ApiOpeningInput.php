<?php

namespace App\Ai\Protected;

use App\Seller\Engine\LeadState;
use App\Seller\Engine\Policy;
use App\Seller\Engine\ResponderRequest;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Runtime\BrandRuntimeSchedule;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Tenancy\TenantContext;
use App\Tenancy\TokenApi\TokenTenantAuthority;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Builds a proactive opening solely from a persisted API lead and published knowledge. */
final class ApiOpeningInput
{
    public function buildLocked(Request $http, TenantContext $tenant, string $leadId): array
    {
        if (DB::transactionLevel() < 1 || config('runtime.ai_driver', 'fake') !== 'fake'
            || ! $tenant->organizationId || ! $tenant->brandId) {
            throw new LogicException('API opening unavailable.');
        }
        app(TokenTenantAuthority::class)->revalidateLocked($http, $tenant, 'leads');
        if (! app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId)) {
            throw new LogicException('API opening unavailable.');
        }
        $schedule = app(BrandRuntimeSchedule::class);
        if ($schedule->mode($tenant, $schedule->settings($tenant), CarbonImmutable::now()) !== 'autonomous') {
            throw new LogicException('API opening unavailable.');
        }
        $scope = ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId];
        $lead = DB::table('seller_api_lead_requests')->where($scope)->where('id', $leadId)
            ->where('endpoint', 'leads')->where('status', 'opening')->lockForUpdate()->first();
        if (! $lead) {
            throw new LogicException('API opening unavailable.');
        }
        $conversation = DB::table('conversations')->where($scope)->where('id', $lead->conversation_id)->lockForUpdate()->first();
        if (! $conversation || $conversation->contact_id !== $lead->contact_id || (int) $conversation->version !== 0
            || $conversation->mode !== 'bot' || $conversation->stage !== 'open' || $conversation->do_not_disturb
            || DB::table('messages')->where($scope)->where('conversation_id', $conversation->id)->exists()) {
            throw new LogicException('API opening unavailable.');
        }
        $stateData = json_decode($conversation->state, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($stateData)) {
            throw new LogicException('API opening unavailable.');
        }
        $state = new LeadState($stateData);
        $knowledge = app(KnowledgeRepository::class)->published($tenant);
        $goal = (new Policy($knowledge))->decide($state, [], true);
        $name = $knowledge->persona()['name'] ?? null;
        $prompt = DB::table('prompt_sets')->where('name', 'responder')->where('version', 'v1')->where('status', 'published')
            ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->first();
        $prompt ??= DB::table('prompt_sets')->where('name', 'responder')->where('version', 'v1')->where('status', 'published')
            ->whereNull('organization_id')->whereNull('brand_id')->first();
        $trusted = file_get_contents(resource_path('views/prompts/responder.blade.php'));
        $model = config('features.E0-05.defaults.seller_defaults.responder.model');
        $temperature = config('features.E0-05.defaults.seller_defaults.responder.temperature');
        if ($goal->kind !== 'intro_and_ask' || ! is_string($name) || trim($name) === '' || ! $prompt
            || ! is_string($trusted) || ! hash_equals(hash('sha256', $prompt->body), $prompt->checksum)
            || ! hash_equals(hash('sha256', $trusted), $prompt->checksum) || ! is_string($model) || ! is_numeric($temperature)) {
            throw new LogicException('API opening unavailable.');
        }
        $system = app(ResponderProjection::class)->render($knowledge, $state, $goal, $name);
        // Proactive opening has no client message; tool execution is not part of this phase.
        $request = app(ResponderRequest::class)->build($system, [], [], $model, (float) $temperature, $prompt->version, $prompt->checksum);
        $context = app(ProtectedRetryContext::class)->make($tenant, $conversation->id, 'responder', 'api-opening-'.$lead->id);

        return [$request, $context, $state, $goal, $knowledge];
    }
}
