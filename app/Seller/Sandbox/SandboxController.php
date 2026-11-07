<?php

namespace App\Seller\Sandbox;

use App\Ai\Protected\DraftResponder;
use App\Models\User;
use App\ProtectedOperations\DraftSimulation\FixtureRuntime;
use App\Seller\Engine\DeterministicGuard;
use App\Seller\Engine\Goal;
use App\Seller\Engine\LeadState;
use App\Seller\Engine\Policy;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

final class SandboxController
{
    private function access(Request $request, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($callback) {
            Gate::forUser($user)->authorize('seller.knowledge.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return $callback($tenant);
        });
    }

    public function index(Request $request): mixed
    {
        $data = $this->access($request, function (TenantContext $tenant) use ($request): array {
            $scope = ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId];

            return [
                'releases' => DB::table('knowledge_releases')->where($scope)->whereIn('status', ['draft', 'published'])->orderByDesc('created_at')->get(['id', 'status', 'version', 'published_at']),
                'clients' => DB::table('contacts')->where($scope)->whereNull('merged_into_id')->orderBy('name')->limit(100)->get(['id', 'name']),
                'channels' => DB::table('channel_accounts')->where($scope)->orderBy('name')->limit(100)->get(['id', 'name', 'type']),
                'sandboxSession' => $request->session()->get($this->sessionKey($tenant)),
            ];
        });

        return Inertia::render('Seller/Sandbox/Index', $data);
    }

    public function reply(Request $request): mixed
    {
        $input = $request->validate(['release' => ['required', 'ulid'], 'text' => ['required', 'string', 'max:4000'], 'history' => ['sometimes', 'array', 'list', 'max:9'], 'history.*' => ['required', 'string', 'max:4000'], 'simulation' => ['sometimes', 'boolean'], 'reset' => ['sometimes', 'boolean'], 'start_new' => ['sometimes', 'boolean'], 'client_id' => ['nullable', 'ulid'], 'channel_id' => ['nullable', 'ulid']]);
        $result = $this->access($request, function (TenantContext $tenant) use ($input, $request): array {
            if ($input['simulation'] ?? false) {
                return $this->simulate($request, $tenant, $input);
            }
            $draft = app(KnowledgeRepository::class)->forRelease($tenant, $input['release'], true);
            $exists = DB::table('knowledge_releases')->where('organization_id', $tenant->organizationId)
                ->where('brand_id', $tenant->brandId)->where('id', $input['release'])->where('status', 'draft')->exists();
            if (! $exists || config('runtime.ai_driver') !== 'fake') {
                return ['status' => 'unavailable'];
            }
            $state = new LeadState;
            $policy = new Policy($draft);
            $questions = [...($input['history'] ?? []), $input['text']];
            foreach ($questions as $index => $question) {
                $extraction = app(FixtureRuntime::class)->extract($request, $draft, $question);
                if ($extraction === null) {
                    return ['status' => 'unavailable'];
                }
                $policy->applyExtraction($state, $extraction);
                $goal = $policy->decide($state, $extraction, $index === 0);
            }
            $answer = app(DraftResponder::class)->respond($request, $draft, $state, $goal, $input['text']);
            if ($answer === null) {
                return ['status' => 'unavailable'];
            }
            $guard = (new DeterministicGuard($draft))->check($answer, $goal, $state);

            return ['status' => 'completed', 'answer' => $answer, 'guard' => $guard, 'goal' => ['kind' => $goal->kind, 'slot' => $goal->slot, 'allowed_tools' => $goal->allowedTools]];
        });

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }

    private function sessionKey(TenantContext $tenant): string
    {
        return 'seller_sandbox.'.hash('sha256', $tenant->organizationId.'|'.$tenant->brandId.'|'.$tenant->actorId);
    }

    private function simulate(Request $request, TenantContext $tenant, array $input): array
    {
        if (config('runtime.ai_driver', 'fake') !== 'fake') {
            return ['status' => 'unavailable'];
        }
        $scope = ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId];
        $release = DB::table('knowledge_releases')->where($scope)->where('id', $input['release'])->whereIn('status', ['draft', 'published'])->first(['id', 'status']) ?? abort(404);
        $clientId = $input['client_id'] ?? null;
        $channelId = $input['channel_id'] ?? null;
        $client = $clientId !== null ? (DB::table('contacts')->where($scope)->where('id', $clientId)->whereNull('merged_into_id')->first(['id', 'name']) ?? abort(404)) : null;
        $channel = $channelId !== null ? (DB::table('channel_accounts')->where($scope)->where('id', $channelId)->first(['id', 'name', 'type']) ?? abort(404)) : null;
        $key = $this->sessionKey($tenant);
        if ($input['reset'] ?? false) {
            $request->session()->forget($key);

            return ['status' => 'reset'];
        }
        $snapshot = app(KnowledgeRepository::class)->forRelease($tenant, $release->id, true);
        $session = $request->session()->get($key);
        $same = ! ($input['start_new'] ?? false) && is_array($session) && ($session['release'] ?? null) === $release->id
            && ($session['checksum'] ?? null) === $snapshot->checksum() && ($session['client_id'] ?? null) === $clientId
            && ($session['channel_id'] ?? null) === $channelId;
        $history = $same ? ($session['history'] ?? []) : [];
        if (count($history) >= 10) {
            return ['status' => 'limit'];
        }
        $result = app(SandboxRuntime::class)->turn($request, $snapshot, $input['text'], $same ? ($session['state'] ?? []) : [], $history, $release->status === 'draft', $client?->name);
        $result['inspector']['client'] = $client?->name;
        $result['inspector']['channel'] = $channel?->name ?: $channel?->type;
        $history[] = ['question' => $input['text'], 'answer' => $result['answer'], 'created_at' => now()->toIso8601String(), 'state' => $result['state'], 'inspector' => $result['inspector']];
        $session = ['release' => $release->id, 'checksum' => $snapshot->checksum(), 'client_id' => $clientId,
            'channel_id' => $channelId, 'state' => $result['state'], 'history' => $history, 'inspector' => $result['inspector']];
        $request->session()->put($key, $session);

        return ['status' => 'completed', ...$session];
    }

    public function check(Request $request): mixed
    {
        $input = $request->validate(['release' => ['required', 'ulid'], 'text' => ['required', 'string', 'max:4000']]);
        $result = $this->access($request, function (TenantContext $tenant) use ($input): array {
            $snapshot = app(KnowledgeRepository::class)->forRelease($tenant, $input['release'], true);

            return (new DeterministicGuard($snapshot))->check($input['text'], new Goal('answer_only'), new LeadState);
        });

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }
}
