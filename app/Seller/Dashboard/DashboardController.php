<?php

namespace App\Seller\Dashboard;

use App\Models\User;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Throwable;

final class DashboardController
{
    private function data(Request $request, bool $graceful = false): ?array
    {
        $input = $request->validate(['days' => ['sometimes', 'integer', 'in:7,30,90']]);

        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($input, $graceful): ?array {
            Gate::forUser($user)->authorize('seller.dialogs.read');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            try {
                return app(DashboardQuery::class)->read($tenant, (int) ($input['days'] ?? 30));
            } catch (Throwable $exception) {
                if (! $graceful) {
                    throw $exception;
                }
                report($exception);

                return null;
            }
        });
    }

    public function index(Request $request): mixed
    {
        $data = $this->data($request, true);

        return Inertia::render('Seller/Dashboard/Index', ['dashboard' => $data, 'initialLoadError' => $data === null]);
    }

    public function attention(Request $request): mixed
    {
        return Inertia::render('Seller/Dashboard/Attention', ['dashboard' => $this->data($request)]);
    }
}
