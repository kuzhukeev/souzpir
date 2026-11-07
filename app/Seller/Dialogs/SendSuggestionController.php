<?php

namespace App\Seller\Dialogs;

use App\Models\Membership;
use App\Models\User;
use App\Seller\Conversations\CanonicalJson;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Sends a suggest-mode draft as the manager's reply without taking the dialog over. */
final class SendSuggestionController
{
    public function store(Request $request, string $dialog, string $turn): mixed
    {
        $input = $request->validate(['text' => ['required', 'string', 'max:5000']]);
        app(ProfileAccess::class)->run($request, function (User $actor, TenantContext $context, $brand, Membership $member) use ($dialog, $turn, $input): void {
            Gate::forUser($actor)->authorize('seller.dialogues.operate');
            abort_unless(app(SellerModuleAccess::class)->enabled($context->organizationId, $context->brandId), 404);
            DB::transaction(function () use ($actor, $context, $dialog, $turn, $input, $member): void {
                $this->assertDialogAccess($context, $dialog, $actor, $member);
                $current = DB::table('seller_turns')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)
                    ->where('id', $turn)->where('conversation_id', $dialog)->first(['status']) ?? abort(404);
                if (in_array($current->status, ['sent', 'edited'], true)) {
                    return;
                }
                $outcome = app(ReadySuggestions::class)->validate($context, $dialog, $turn);
                if ($outcome === null) {
                    throw ValidationException::withMessages(['text' => 'Черновик устарел.']);
                }
                $text = trim($input['text']);
                $status = $text === trim($outcome['messages'][0]['text']) ? 'sent' : 'edited';
                $messageId = (string) Str::ulid();
                DB::table('messages')->insert(['id' => $messageId, 'organization_id' => $context->organizationId, 'brand_id' => $context->brandId,
                    'conversation_id' => $dialog, 'role' => 'manager', 'text' => $text,
                    'meta' => json_encode(['delivery' => 'fake'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
                DB::table('seller_turns')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)
                    ->where('id', $turn)->where('status', 'suggested')->update([
                        'status' => $status, 'sent_by_user_id' => $actor->id, 'sent_at' => now(), 'sent_message_id' => $messageId, 'updated_at' => now(),
                    ]);
                app(AuditWriter::class)->record($context, new AuditEntry('seller.dialog.suggestion.'.$status, 'conversation', $dialog));
                DeliverManagerMessage::dispatch($context->organizationId, $context->brandId, $dialog, $messageId)->afterCommit();
            });
        });

        return $this->respond($request, $dialog, 'Ответ отправлен.');
    }

    /** Leaves the draft unsent; the manager will answer the dialog manually. */
    public function skip(Request $request, string $dialog, string $turn): mixed
    {
        app(ProfileAccess::class)->run($request, function (User $actor, TenantContext $context, $brand, Membership $member) use ($dialog, $turn): void {
            Gate::forUser($actor)->authorize('seller.dialogues.operate');
            abort_unless(app(SellerModuleAccess::class)->enabled($context->organizationId, $context->brandId), 404);
            DB::transaction(function () use ($actor, $context, $dialog, $turn, $member): void {
                $this->assertDialogAccess($context, $dialog, $actor, $member);
                $this->resolve($context, $dialog, $turn, 'skipped');
            });
        });

        return $this->respond($request, $dialog, 'Подсказка пропущена.');
    }

    /** Flags the draft for attention without sending it; removes it from the ready queue. */
    public function mark(Request $request, string $dialog, string $turn): mixed
    {
        app(ProfileAccess::class)->run($request, function (User $actor, TenantContext $context, $brand, Membership $member) use ($dialog, $turn): void {
            Gate::forUser($actor)->authorize('seller.dialogues.operate');
            abort_unless(app(SellerModuleAccess::class)->enabled($context->organizationId, $context->brandId), 404);
            DB::transaction(function () use ($actor, $context, $dialog, $turn, $member): void {
                $this->assertDialogAccess($context, $dialog, $actor, $member);
                $this->resolve($context, $dialog, $turn, 'flagged');
            });
        });

        return $this->respond($request, $dialog, 'Подсказка помечена.');
    }

    private function assertDialogAccess(TenantContext $context, string $dialog, User $actor, Membership $member): void
    {
        $conversation = DB::table('conversations')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)
            ->where('id', $dialog)->first(['assigned_user_id']) ?? abort(404);
        if ($member->role === 'member' && ($member->settings['sales_manager'] ?? false) && $conversation->assigned_user_id !== $actor->id) {
            abort(404);
        }
    }

    /** Leaves the turn status as 'suggested' (no schema change) and marks the cached outcome resolved, so it drops out of the ready queue without being sendable again. */
    private function resolve(TenantContext $context, string $dialog, string $turn, string $status): void
    {
        $outcome = app(ReadySuggestions::class)->validate($context, $dialog, $turn);
        if ($outcome === null) {
            return;
        }
        $outcome['resolved'] = $status;
        DB::table('seller_turns')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)
            ->where('id', $turn)->where('conversation_id', $dialog)->where('status', 'suggested')->update([
                'outcome' => json_encode($outcome, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'outcome_hash' => CanonicalJson::hash($outcome), 'updated_at' => now(),
            ]);
        app(AuditWriter::class)->record($context, new AuditEntry('seller.dialog.suggestion.'.$status, 'conversation', $dialog));
    }

    /** XHR callers (the queue page) get JSON; Inertia visits (the dialog thread widget) get a redirect. */
    private function respond(Request $request, string $dialog, string $message): mixed
    {
        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['status' => 'ok']);
        }

        return redirect('/seller/dialogs/'.$dialog)->with('status', $message);
    }
}
