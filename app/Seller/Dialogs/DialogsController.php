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
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class DialogsController
{
    public function index(Request $request, DialogReader $reader, ?string $dialog = null): Response
    {
        $input = $request->validate([
            'mode' => ['sometimes', 'string', Rule::in(['all', 'bot', 'suggest', 'manager', 'paused'])],
            'search' => ['sometimes', 'string', 'max:100'],
        ]);
        $data = app(ProfileAccess::class)->run($request, function (User $actor, TenantContext $context, $brand, Membership $member) use ($reader, $dialog, $input): array {
            Gate::forUser($actor)->authorize('seller.dialogs.read');
            abort_unless(app(SellerModuleAccess::class)->enabled($context->organizationId, $context->brandId), 404);
            $manager = $member->role === 'member' && ($member->settings['sales_manager'] ?? false);

            return $reader->read($context, $actor->id, $manager, ! $manager && $member->role !== 'viewer', $dialog, $input['mode'] ?? 'all', trim($input['search'] ?? ''));
        });

        return Inertia::render('Seller/Dialogs/Index', $data + ['filters' => ['mode' => $input['mode'] ?? 'all', 'search' => $input['search'] ?? '']]);
    }

    public function mode(Request $request, string $dialog): RedirectResponse
    {
        $input = $request->validate(['action' => ['required', Rule::in(['take', 'return'])]]);
        app(ProfileAccess::class)->run($request, function (User $actor, TenantContext $context, $brand, Membership $member) use ($dialog, $input): void {
            Gate::forUser($actor)->authorize('seller.dialogues.operate');
            abort_unless(app(SellerModuleAccess::class)->enabled($context->organizationId, $context->brandId), 404);
            $row = DB::table('conversations')->where('organization_id', $context->organizationId)
                ->where('brand_id', $context->brandId)->where('id', $dialog)->lockForUpdate()->first() ?? abort(404);
            $manager = $member->role === 'member' && ($member->settings['sales_manager'] ?? false);
            if ($manager && $row->assigned_user_id !== $actor->id && ! ($row->mode === 'manager' && $row->assigned_user_id === null)) {
                abort(404);
            }
            if ($input['action'] === 'take') {
                if ($row->mode === 'manager' && $row->assigned_user_id !== null && $row->assigned_user_id !== $actor->id) {
                    throw ValidationException::withMessages(['action' => 'Диалог уже ведёт другой сотрудник.']);
                }
                if (! in_array($row->mode, ['bot', 'suggest', 'manager'], true)) {
                    throw ValidationException::withMessages(['action' => 'Этот диалог сейчас нельзя забрать.']);
                }
                $after = ['mode' => 'manager', 'assigned_user_id' => $actor->id, 'taken_at' => now()];
            } else {
                if ($row->mode !== 'manager' || $row->assigned_user_id === null) {
                    throw ValidationException::withMessages(['action' => 'Диалог уже у бота.']);
                }
                if ($manager && $row->assigned_user_id !== $actor->id) {
                    abort(404);
                }
                $after = ['mode' => 'bot', 'assigned_user_id' => null, 'taken_at' => null];
            }
            DB::table('conversations')->where('id', $row->id)->update($after + ['version' => $row->version + 1, 'updated_at' => now()]);
            app(AuditWriter::class)->record($context, new AuditEntry('seller.dialog.mode_changed', 'conversation', $row->id,
                before: ['mode' => $row->mode, 'assigned_user_id' => $row->assigned_user_id],
                after: ['mode' => $after['mode'], 'assigned_user_id' => $after['assigned_user_id']]));
        });

        return redirect('/seller/dialogs/'.$dialog);
    }
}
