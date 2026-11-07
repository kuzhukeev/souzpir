<?php

namespace App\Seller\Dialogs;

use App\Models\Membership;
use App\Models\User;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DialogTaskController
{
    public function store(Request $request, string $dialog): RedirectResponse
    {
        $input = $request->validate(['text' => ['required', 'string', 'max:1000'], 'due_at' => ['required', 'date', 'after:now']]);
        app(ProfileAccess::class)->run($request, function (User $actor, TenantContext $context, $brand, Membership $member) use ($dialog, $input): void {
            Gate::forUser($actor)->authorize('seller.dialogues.operate');
            abort_unless(app(SellerModuleAccess::class)->enabled($context->organizationId, $context->brandId), 404);
            $row = DB::table('conversations')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)->where('id', $dialog)->first() ?? abort(404);
            if ($member->role === 'member' && ($member->settings['sales_manager'] ?? false) && $row->assigned_user_id !== $actor->id) {
                abort(404);
            }
            $id = (string) Str::ulid();
            DB::table('seller_tasks')->insert(['id' => $id, 'organization_id' => $context->organizationId, 'brand_id' => $context->brandId,
                'conversation_id' => $row->id, 'contact_id' => $row->contact_id, 'type' => 'reminder',
                'payload' => json_encode(['text' => $input['text']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'due_at' => $input['due_at'],
                'status' => 'new', 'priority' => 'normal', 'assigned_user_id' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            app(AuditWriter::class)->record($context, new AuditEntry('seller.dialog.reminder.created', 'seller_task', $id));
        });

        return back()->with('status', 'Напоминание создано.');
    }

    public function complete(Request $request, string $dialog, string $task): RedirectResponse
    {
        app(ProfileAccess::class)->run($request, function (User $actor, TenantContext $context, $brand, Membership $member) use ($dialog, $task): void {
            Gate::forUser($actor)->authorize('seller.dialogues.operate');
            abort_unless(app(SellerModuleAccess::class)->enabled($context->organizationId, $context->brandId), 404);
            $row = DB::table('conversations')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)->where('id', $dialog)->first() ?? abort(404);
            if ($member->role === 'member' && ($member->settings['sales_manager'] ?? false) && $row->assigned_user_id !== $actor->id) {
                abort(404);
            }
            $item = DB::table('seller_tasks')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)
                ->where('conversation_id', $dialog)->where('id', $task)->first() ?? abort(404);
            if (! in_array($item->status, ['new', 'in_progress'], true)) {
                throw ValidationException::withMessages(['task' => 'Задача уже завершена.']);
            }
            DB::table('seller_tasks')->where('id', $task)->update(['status' => 'done', 'updated_at' => now()]);
            app(AuditWriter::class)->record($context, new AuditEntry('seller.task.status.updated', 'seller_task', $task, before: ['status' => $item->status], after: ['status' => 'done']));
        });

        return back()->with('status', 'Задача выполнена.');
    }
}
