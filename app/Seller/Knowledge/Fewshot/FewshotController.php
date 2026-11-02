<?php

namespace App\Seller\Knowledge\Fewshot;

use App\Models\User;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

final class FewshotController
{
    private function access(Request $request, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($callback) {
            Gate::forUser($user)->authorize('seller.knowledge.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return $callback($tenant);
        });
    }

    private function draft(TenantContext $tenant): ?object
    {
        return DB::table('knowledge_releases')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('status', 'draft')->first(['id', 'version']);
    }

    public function index(Request $request): mixed
    {
        $data = $this->access($request, function (TenantContext $tenant): array {
            $draft = $this->draft($tenant);
            $snapshot = $draft ? app(KnowledgeRepository::class)->forRelease($tenant, $draft->id, true) : null;

            return ['draft' => $draft, 'fewshot' => $snapshot?->all('fewshot')];
        });

        return Inertia::render('Seller/Knowledge/Fewshot/Index', [...$data, 'status' => session('status')]);
    }

    public function save(Request $request): mixed
    {
        $input = $request->validate(['version' => ['required', 'integer', 'min:1'], 'index' => ['nullable', 'integer', 'min:0'], 'bucket' => ['required', 'in:examples,disabled_examples'], 'tag' => ['required', 'string', 'max:100'], 'text' => ['required', 'string', 'max:2000'], 'situation' => ['nullable', 'string', 'max:500'], 'client_type' => ['required', 'string', 'max:64'], 'confidence' => ['required', 'numeric', 'min:0', 'max:1'], 'source' => ['nullable', 'string', 'max:200'], 'enabled' => ['required', 'boolean']]);
        $this->access($request, function (TenantContext $tenant) use ($input): void {
            $draft = $this->draft($tenant) ?? abort(404);
            $repo = app(KnowledgeRepository::class);
            $data = $repo->forRelease($tenant, $draft->id, true)->toArray();
            $fewshot = $data['fewshot'];
            $fewshot['examples'] ??= [];
            $fewshot['disabled_examples'] ??= [];
            $existing = [];
            if (isset($input['index'])) {
                $bucket = $input['bucket'];
                $index = (int) $input['index'];
                $existing = $fewshot[$bucket][$index] ?? abort(404);
                array_splice($fewshot[$bucket], $index, 1);
            }
            $item = array_replace($existing, ['tag' => trim($input['tag']), 'text' => trim($input['text']), 'client_type' => trim($input['client_type']), 'confidence' => (float) $input['confidence'], 'source' => trim($input['source'] ?? ''), 'situation' => trim($input['situation'] ?? '')]);
            $destination = $input['enabled'] ? 'examples' : 'disabled_examples';
            if (isset($index) && $bucket === $destination) {
                array_splice($fewshot[$destination], $index, 0, [$item]);
            } else {
                $fewshot[$destination][] = $item;
            }
            $data['fewshot'] = $fewshot;
            $repo->saveDraft($tenant, $draft->id, (int) $input['version'], $data);
        });

        return back()->with('status', 'Образец сохранён в черновике.');
    }

    public function preview(Request $request): mixed
    {
        $input = $request->validate(['tag' => ['required', 'string', 'max:100'], 'client_type' => ['nullable', 'string', 'max:64']]);
        $examples = $this->access($request, function (TenantContext $tenant) use ($input): array {
            $draft = $this->draft($tenant) ?? abort(404);

            return app(KnowledgeRepository::class)->forRelease($tenant, $draft->id, true)->fewshot([$input['tag']], $input['client_type'] ?? null, 6, 0.7);
        });

        return response()->json(['examples' => $examples])->header('Cache-Control', 'private, no-store');
    }
}
