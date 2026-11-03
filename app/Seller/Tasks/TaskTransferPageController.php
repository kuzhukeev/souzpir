<?php

namespace App\Seller\Tasks;

use App\Models\Membership;
use App\Models\User;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class TaskTransferPageController
{
    public function __invoke(Request $request, ProfileAccess $access): Response
    {
        $data = $access->run($request, function (User $actor, TenantContext $context): array {
            Gate::forUser($actor)->authorize('seller.dialogues.operate');
            abort_unless(app(SellerModuleAccess::class)->enabled($context->organizationId, $context->brandId), 404);
            $tasks = DB::table('seller_tasks')->join('users', 'users.id', '=', 'seller_tasks.assigned_user_id')
                ->where('seller_tasks.organization_id', $context->organizationId)
                ->where('seller_tasks.brand_id', $context->brandId)->whereIn('seller_tasks.status', ['new', 'in_progress'])
                ->orderByDesc('seller_tasks.created_at')->get(['seller_tasks.id', 'seller_tasks.type', 'seller_tasks.assigned_user_id', 'users.name as assignee']);
            $members = Membership::withoutGlobalScopes()->with('user')->where('organization_id', $context->organizationId)
                ->where('status', 'active')->get();
            $recipients = $members->filter(function (Membership $member) use ($context): bool {
                if (! $member->user || ! $member->permitsBrand($context->brandId)) {
                    return false;
                }
                $recipient = new TenantContext($context->organizationId, $context->brandId, $member->user_id, $member->id);

                return app(TenantContextRunner::class)->run($recipient, fn () => Gate::forUser($member->user)->allows('seller.dialogues.operate'));
            })->map(fn (Membership $member): array => ['id' => $member->user_id, 'name' => $member->user->name])->values();

            return ['tasks' => $tasks, 'recipients' => $recipients];
        });

        return Inertia::render('Seller/Tasks/Transfer', $data);
    }
}
