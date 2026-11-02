<?php

namespace App\Seller\Knowledge\Escalation;

use App\Models\User;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class EscalationController
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
        return $this->access($request, function (TenantContext $tenant): mixed {
            $draft = $this->draft($tenant);
            $escalation = $draft ? app(KnowledgeRepository::class)->forRelease($tenant, $draft->id, true)->escalation() : null;

            return Inertia::render('Seller/Knowledge/Escalation/Index', ['draft' => $draft, 'escalation' => $escalation, 'status' => session('status')]);
        });
    }

    public function save(Request $request): mixed
    {
        $input = $request->validate(['version' => ['required', 'integer', 'min:1'],
            'defaults' => ['required', 'array'], 'defaults.response_time_text' => ['required', 'string', 'max:500'], 'defaults.assignee' => ['required', 'string', 'max:120'],
            'reasons' => ['present', 'array', 'max:100'], 'reasons.*.id' => ['required', 'string', 'max:80', 'regex:/^[a-zA-Z0-9_-]+$/', 'distinct'],
            'reasons.*.title' => ['required', 'string', 'max:255'], 'reasons.*.detect' => ['required', 'string', 'max:1000'],
            'reasons.*.priority' => ['required', Rule::in(['normal', 'urgent'])], 'reasons.*.message_idea' => ['required', 'string', 'max:2000']]);
        $this->access($request, function (TenantContext $tenant) use ($input): void {
            $draft = $this->draft($tenant) ?? abort(404, 'Создайте черновик базы знаний.');
            $repository = app(KnowledgeRepository::class);
            $data = $repository->forRelease($tenant, $draft->id, true)->toArray();
            $existing = collect($data['escalation']['reasons'] ?? [])->keyBy('id');
            $data['escalation']['defaults'] = array_replace($data['escalation']['defaults'] ?? [], $input['defaults']);
            $data['escalation']['reasons'] = collect($input['reasons'])->map(fn ($reason) => array_replace($existing->get($reason['id'], []), $reason))->all();
            $repository->saveDraft($tenant, $draft->id, (int) $input['version'], $data);
        });

        return back()->with('status', 'Причины передачи сохранены в черновике.');
    }
}
