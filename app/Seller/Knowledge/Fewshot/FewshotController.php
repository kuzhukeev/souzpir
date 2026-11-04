<?php

namespace App\Seller\Knowledge\Fewshot;

use App\Models\Membership;
use App\Models\User;
use App\Seller\Dialogs\DialogReader;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
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

            $persona = $snapshot?->all('persona');
            $maxWords = $persona['length']['max_words'] ?? null;

            return ['draft' => $draft, 'fewshot' => $snapshot?->all('fewshot'), 'maxWords' => is_int($maxWords) && $maxWords > 0 ? $maxWords : null];
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

            $snapshot = app(KnowledgeRepository::class)->forRelease($tenant, $draft->id, true);

            return ['examples' => $snapshot->fewshot([$input['tag']], $input['client_type'] ?? null, 6, 0.7),
                'bad_examples' => array_slice($snapshot->badExamples(), 0, 8)];
        });

        return response()->json($examples)->header('Cache-Control', 'private, no-store');
    }

    public function saveBad(Request $request): mixed
    {
        $input = $request->validate(['version' => ['required', 'integer', 'min:1'], 'index' => ['nullable', 'integer', 'min:0'],
            'text' => ['required', 'string', 'max:2000'], 'why' => ['required', 'string', 'max:500']]);
        $this->access($request, function (TenantContext $tenant) use ($input): void {
            $draft = $this->draft($tenant) ?? abort(404);
            $repo = app(KnowledgeRepository::class);
            $data = $repo->forRelease($tenant, $draft->id, true)->toArray();
            $data['fewshot']['bad_examples'] ??= [];
            $item = ['text' => trim($input['text']), 'why' => trim($input['why'])];
            if ($item['text'] === '' || $item['why'] === '') {
                throw ValidationException::withMessages(['text' => 'Укажите текст и причину.']);
            }
            if (isset($input['index'])) {
                $index = (int) $input['index'];
                abort_unless(isset($data['fewshot']['bad_examples'][$index]), 404);
                $data['fewshot']['bad_examples'][$index] = $item;
            } else {
                $data['fewshot']['bad_examples'][] = $item;
            }
            $repo->saveDraft($tenant, $draft->id, (int) $input['version'], $data);
        });

        return back()->with('status', 'Плохой образец сохранён в черновике.');
    }

    public function deleteBad(Request $request): mixed
    {
        $input = $request->validate(['version' => ['required', 'integer', 'min:1'], 'index' => ['required', 'integer', 'min:0']]);
        $this->access($request, function (TenantContext $tenant) use ($input): void {
            $draft = $this->draft($tenant) ?? abort(404);
            $repo = app(KnowledgeRepository::class);
            $data = $repo->forRelease($tenant, $draft->id, true)->toArray();
            abort_unless(isset($data['fewshot']['bad_examples'][$input['index']]), 404);
            array_splice($data['fewshot']['bad_examples'], (int) $input['index'], 1);
            $repo->saveDraft($tenant, $draft->id, (int) $input['version'], $data);
        });

        return back()->with('status', 'Плохой образец удалён из черновика.');
    }

    private function readableDialogs(Request $request, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant, $brand, Membership $member) use ($callback): mixed {
            Gate::forUser($user)->authorize('seller.knowledge.write');
            Gate::forUser($user)->authorize('seller.dialogs.read');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);
            $manager = $member->role === 'member' && ($member->settings['sales_manager'] ?? false);

            return $callback($tenant, $user, $manager);
        });
    }

    public function dialogs(Request $request): mixed
    {
        $input = $request->validate(['search' => ['sometimes', 'string', 'max:100']]);
        $list = $this->readableDialogs($request, fn (TenantContext $tenant, User $user, bool $manager) => app(DialogReader::class)->read($tenant, $user->id, $manager, false, null, 'all', trim($input['search'] ?? ''))['dialogs']);

        return response()->json(['dialogs' => array_map(fn (array $dialog) => ['id' => $dialog['id'], 'name' => $dialog['name']], $list)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function messages(Request $request, string $dialog): mixed
    {
        $messages = $this->readableDialogs($request, fn (TenantContext $tenant, User $user, bool $manager) => app(DialogReader::class)->read($tenant, $user->id, $manager, false, $dialog, 'all', '')['dialog']['messages']);

        return response()->json(['messages' => array_values(array_map(fn (array $message) => ['id' => $message['id'], 'text' => $message['text']],
            array_filter($messages, fn (array $message) => $message['role'] === 'manager')))])
            ->header('Cache-Control', 'private, no-store');
    }

    public function fromDialog(Request $request): mixed
    {
        $input = $request->validate(['version' => ['required', 'integer', 'min:1'], 'dialog' => ['required', 'ulid'],
            'message' => ['required', 'ulid'], 'tag' => ['required', 'string', 'max:100'],
            'situation' => ['nullable', 'string', 'max:500'], 'confidence' => ['required', 'numeric', 'min:0', 'max:1']]);
        $this->readableDialogs($request, function (TenantContext $tenant, User $user, bool $manager) use ($input): void {
            $detail = app(DialogReader::class)->read($tenant, $user->id, $manager, false, $input['dialog'], 'all', '')['dialog'];
            $message = collect($detail['messages'])->first(fn (array $row) => $row['id'] === $input['message'] && $row['role'] === 'manager');
            abort_unless($message, 404);
            if (trim($input['tag']) === '' || trim($message['text']) === '' || mb_strlen($message['text']) > 2000) {
                throw ValidationException::withMessages(['message' => 'Выберите короткую реплику менеджера и укажите тег.']);
            }
            $draft = $this->draft($tenant) ?? abort(404);
            $repo = app(KnowledgeRepository::class);
            $data = $repo->forRelease($tenant, $draft->id, true)->toArray();
            $data['fewshot']['examples'] ??= [];
            $data['fewshot']['examples'][] = ['tag' => trim($input['tag']), 'text' => $message['text'], 'client_type' => 'all',
                'confidence' => (float) $input['confidence'], 'source' => 'dialog #'.substr($input['dialog'], -6),
                'situation' => trim($input['situation'] ?? '')];
            $repo->saveDraft($tenant, $draft->id, (int) $input['version'], $data);
        });

        return back()->with('status', 'Реплика менеджера добавлена в черновик.');
    }
}
