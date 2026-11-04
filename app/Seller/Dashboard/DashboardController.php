<?php

namespace App\Seller\Dashboard;

use App\Models\User;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

final class DashboardController
{
    private function data(Request $request): array
    {
        $input = $request->validate(['days' => ['sometimes', 'integer', 'in:7,30,90']]);

        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($input): array {
            Gate::forUser($user)->authorize('seller.dialogs.read');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return app(DashboardQuery::class)->read($tenant, (int) ($input['days'] ?? 30));
        });
    }

    public function index(Request $request): mixed
    {
        try {
            return Inertia::render('Seller/Dashboard/Index', ['dashboard' => $this->data($request)]);
        } catch (QueryException $exception) {
            report($exception);

            return Inertia::render('Seller/Dashboard/Index', ['dashboard' => null, 'initialLoadError' => true]);
        }
    }

    public function attention(Request $request): mixed
    {
        return Inertia::render('Seller/Dashboard/Attention', ['dashboard' => $this->data($request)]);
    }
}
