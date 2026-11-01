<?php

namespace App\Seller\Tasks;

use App\Models\Membership;
use App\Models\User;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class TransferTaskController
{
    public function __invoke(Request $request, string $task): JsonResponse
    {
        $data = $request->validate(['assigned_user_id' => ['required', 'ulid']]);

        app(ProfileAccess::class)->run($request, function (User $actor, TenantContext $context) use ($data, $task): void {
            Gate::forUser($actor)->authorize('seller.dialogues.operate');
            abort_unless(app(SellerModuleAccess::class)->enabled($context->organizationId, $context->brandId), 404);
            $row = DB::table('seller_tasks')->where('organization_id', $context->organizationId)
                ->where('brand_id', $context->brandId)->where('id', $task)->first() ?? abort(404);
            if ($row->status !== 'new' || $row->assigned_user_id === null) {
                throw ValidationException::withMessages(['task' => 'Передать можно только активное назначение.']);
            }
            $recipient = Membership::withoutGlobalScopes()->where('organization_id', $context->organizationId)
                ->where('user_id', $data['assigned_user_id'])->where('status', 'active')->first();
            if (! $recipient || ! $recipient->permitsBrand($context->brandId) || $recipient->user_id === $row->assigned_user_id) {
                throw ValidationException::withMessages(['assigned_user_id' => 'Выберите подходящего сотрудника.']);
            }
            $user = User::find($recipient->user_id);
            $recipientContext = new TenantContext($context->organizationId, $context->brandId, $recipient->user_id, $recipient->id);
            $allowed = $user && app(TenantContextRunner::class)->run($recipientContext,
                fn () => Gate::forUser($user)->allows('seller.dialogues.operate'));
            if (! $allowed) {
                throw ValidationException::withMessages(['assigned_user_id' => 'Выберите подходящего сотрудника.']);
            }
            DB::table('seller_tasks')->where('id', $row->id)->update(['assigned_user_id' => $recipient->user_id, 'updated_at' => now()]);
            app(AuditWriter::class)->record($context, new AuditEntry('seller.task.transferred', 'seller_task', $row->id,
                before: ['assigned_user_id' => $row->assigned_user_id], after: ['assigned_user_id' => $recipient->user_id]));
        });

        return response()->json(['data' => ['id' => $task, 'assigned_user_id' => $data['assigned_user_id']]])->header('Cache-Control', 'private, no-store');
    }
}
