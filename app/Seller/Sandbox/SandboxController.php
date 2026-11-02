<?php

namespace App\Seller\Sandbox;

use App\Models\User;
use App\Seller\Engine\DeterministicGuard;
use App\Seller\Engine\Goal;
use App\Seller\Engine\LeadState;
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
        $releases = $this->access($request, fn (TenantContext $tenant) => DB::table('knowledge_releases')->where('organization_id', $tenant->organizationId)
            ->where('brand_id', $tenant->brandId)->whereIn('status', ['draft', 'published'])->orderByDesc('created_at')->get(['id', 'status', 'version', 'published_at']));

        return Inertia::render('Seller/Sandbox/Index', ['releases' => $releases]);
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
