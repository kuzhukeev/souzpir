<?php

namespace App\Seller\Followups;

use App\Models\User;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

final class FollowupsController
{
    private function access(Request $request, string $permission, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($permission, $callback) {
            Gate::forUser($user)->authorize($permission);
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return $callback($user, $tenant);
        });
    }

    public function index(Request $request): mixed
    {
        $input = $request->validate(['days' => ['nullable', 'integer', 'in:7,30,90']]);
        $days = (int) ($input['days'] ?? 30);
        $data = $this->access($request, 'seller.dialogues.operate', function (User $user, TenantContext $tenant) use ($days): array {
            $rows = DB::table('seller_followup_candidates as f')
                ->join('conversations as c', 'c.id', '=', 'f.conversation_id')
                ->leftJoin('contacts as p', 'p.id', '=', 'c.contact_id')
                ->leftJoin('channel_accounts as a', 'a.id', '=', 'c.channel_account_id')
                ->where('f.organization_id', $tenant->organizationId)->where('f.brand_id', $tenant->brandId)
                ->where('f.created_at', '>=', now()->subDays($days))->orderByDesc('f.created_at')
                ->get(['f.id', 'f.text', 'f.status', 'f.created_at', 'f.updated_at', 'p.name as contact', 'a.type as channel']);

            $replies = DB::table('seller_followup_candidates as f')
                ->where('f.organization_id', $tenant->organizationId)->where('f.brand_id', $tenant->brandId)
                ->where('f.status', 'completed')->where('f.created_at', '>=', now()->subDays($days))
                ->whereExists(function ($query): void {
                    $query->selectRaw('1')->from('messages as m')->whereColumn('m.conversation_id', 'f.conversation_id')
                        ->whereColumn('m.organization_id', 'f.organization_id')->whereColumn('m.brand_id', 'f.brand_id')
                        ->where('m.role', 'client')->whereColumn('m.created_at', '>', 'f.updated_at');
                })->distinct()->count('f.conversation_id');

            return ['items' => $rows, 'counts' => ['scheduled' => $rows->where('status', 'pending')->count(), 'sent' => $rows->where('status', 'completed')->count(), 'replies_after_test' => $replies], 'canCancel' => Gate::forUser($user)->allows('seller.dialogues.operate')];
        });

        return Inertia::render('Seller/Followups/Index', [...$data, 'days' => $days, 'status' => session('status')]);
    }

    public function cancel(Request $request, string $candidate): mixed
    {
        $this->access($request, 'seller.dialogues.operate', function (User $user, TenantContext $tenant) use ($candidate): void {
            $query = DB::table('seller_followup_candidates')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('id', $candidate);
            $row = $query->first(['status']);
            abort_unless($row, 404);
            abort_unless($row->status === 'pending', 422, 'Отменить можно только запланированное напоминание.');
            $query->update(['status' => 'cancelled', 'updated_at' => now()]);
        });

        return back()->with('status', 'Напоминание отменено.');
    }
}
