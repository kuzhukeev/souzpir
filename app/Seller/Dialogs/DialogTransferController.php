<?php

namespace App\Seller\Dialogs;

use App\Models\Membership;
use App\Models\User;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeNotReady;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class DialogTransferController
{
    public function candidates(TenantContext $context): array
    {
        return Membership::withoutGlobalScopes()->with('user')->where('organization_id', $context->organizationId)->where('status', 'active')->get()
            ->filter(function (Membership $member) use ($context): bool {
                if (! $member->user || ! $member->permitsBrand($context->brandId)) {
                    return false;
                }
                $recipient = new TenantContext($context->organizationId, $context->brandId, $member->user_id, $member->id);

                return app(TenantContextRunner::class)->run($recipient, fn () => Gate::forUser($member->user)->allows('seller.dialogues.operate'));
            })->map(fn (Membership $member): array => ['id' => $member->user_id, 'name' => $member->user->name])->values()->all();
    }

    public function transfer(Request $request, string $dialog): RedirectResponse
    {
        $input = $request->validate(['assigned_user_id' => ['required', 'ulid']]);
        app(ProfileAccess::class)->run($request, function (User $actor, TenantContext $context, $brand, Membership $member) use ($dialog, $input): void {
            Gate::forUser($actor)->authorize('seller.dialogues.operate');
            abort_unless(app(SellerModuleAccess::class)->enabled($context->organizationId, $context->brandId), 404);
            $query = DB::table('conversations')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)->where('id', $dialog);
            $row = $query->first() ?? abort(404);
            if ($member->role === 'member' && ($member->settings['sales_manager'] ?? false) && $row->assigned_user_id !== $actor->id) {
                abort(404);
            }
            try {
                $fields = app(KnowledgeRepository::class)->published($context)->policy()['client_fields'] ?? [];
                $state = json_decode($row->state ?: '{}', true);
                $slots = is_array($state['slots'] ?? null) ? $state['slots'] : [];
                $missing = collect($fields)->filter(function ($field) use ($slots): bool {
                    $id = $field['id'] ?? null;
                    $value = is_string($id) ? ($slots[$id] ?? null) : null;

                    return ($field['required'] ?? false) && ($field['stage'] ?? null) === 'handoff'
                        && is_string($id) && ($value === null || $value === '' || $value === []);
                })->map(fn ($field) => $field['name'] ?? $field['id'])->all();
                if ($missing !== []) {
                    throw ValidationException::withMessages(['assigned_user_id' => 'Перед передачей заполните: '.implode(', ', $missing).'.']);
                }
            } catch (KnowledgeNotReady) {
                // Without a published schema, no additional transfer fields apply.
            }
            $recipient = collect($this->candidates($context))->firstWhere('id', $input['assigned_user_id']);
            if (! $recipient) {
                throw ValidationException::withMessages(['assigned_user_id' => 'Выберите сотрудника с доступом к бренду и диалогам.']);
            }
            $query->update(['mode' => 'manager', 'assigned_user_id' => $recipient['id'], 'taken_at' => now(), 'version' => $row->version + 1, 'updated_at' => now()]);
            app(AuditWriter::class)->record($context, new AuditEntry('seller.dialog.transferred', 'conversation', $row->id,
                before: ['assigned_user_id' => $row->assigned_user_id], after: ['assigned_user_id' => $recipient['id'], 'recipient_name' => $recipient['name']]));
        });

        return redirect('/seller/dialogs/'.$dialog)->with('status', 'Диалог передан.');
    }
}
