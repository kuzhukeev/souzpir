<?php

namespace App\Seller\Evaluation;

use App\Models\User;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class ConversationEvaluationController
{
    public function __invoke(Request $request, string $dialog): mixed
    {
        $id = app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($dialog): string {
            Gate::forUser($user)->authorize('seller.knowledge.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return app(ConversationFakeJudge::class)->evaluate($tenant, $dialog);
        });

        return redirect('/seller/evaluations/'.$id);
    }
}
