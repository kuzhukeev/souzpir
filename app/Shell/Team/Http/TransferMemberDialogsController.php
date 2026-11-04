<?php

namespace App\Shell\Team\Http;

use App\Models\Membership;
use App\Models\User;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class TransferMemberDialogsController
{
    public function __invoke(Request $request, string $membership): mixed
    {
        Gate::authorize('team.manage');
        Gate::authorize('seller.dialogues.operate');
        $input = $request->validate(['recipient_id' => ['required', 'ulid']]);
        $tenant = app(TenantContext::class);
        abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);
        $source = Membership::query()->findOrFail($membership);
        Gate::authorize('view', $source);
        abort_unless($source->permitsBrand($tenant->brandId), 404);
        $recipient = Membership::query()->where('status', 'active')->where('user_id', $input['recipient_id'])->first();
        if (! $recipient || ! $recipient->permitsBrand($tenant->brandId) || $recipient->user_id === $source->user_id) {
            throw ValidationException::withMessages(['recipient_id' => 'Выберите доступного коллегу.']);
        }
        $user = User::find($recipient->user_id);
        $context = new TenantContext($tenant->organizationId, $tenant->brandId, $recipient->user_id, $recipient->id);
        if (! $user || ! app(TenantContextRunner::class)->run($context, fn () => Gate::forUser($user)->allows('seller.dialogues.operate'))) {
            throw ValidationException::withMessages(['recipient_id' => 'Выберите доступного коллегу.']);
        }
        DB::transaction(function () use ($tenant, $source, $recipient): void {
            $dialogs = DB::table('conversations')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->where('assigned_user_id', $source->user_id)->where('mode', 'manager')->get(['id', 'version']);
            foreach ($dialogs as $dialog) {
                DB::table('conversations')->where('id', $dialog->id)->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                    ->update(['assigned_user_id' => $recipient->user_id, 'version' => $dialog->version + 1, 'updated_at' => now()]);
                app(AuditWriter::class)->record($tenant, new AuditEntry('seller.dialog.transferred', 'conversation', $dialog->id,
                    before: ['assigned_user_id' => $source->user_id], after: ['assigned_user_id' => $recipient->user_id]));
            }
        });

        return back();
    }
}
