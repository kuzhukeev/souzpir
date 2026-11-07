<?php

namespace App\Seller\Dialogs;

use App\Models\Membership;
use App\Models\User;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class DialogMessageController
{
    public function store(Request $request, string $dialog): RedirectResponse
    {
        $input = $request->validate(['text' => ['nullable', 'required_without:attachment', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf,txt']]);
        $live = false;
        app(ProfileAccess::class)->run($request, function (User $actor, TenantContext $context, $brand, Membership $member) use ($dialog, $input, $request, &$live): void {
            Gate::forUser($actor)->authorize('seller.dialogues.operate');
            abort_unless(app(SellerModuleAccess::class)->enabled($context->organizationId, $context->brandId), 404);
            $conversation = DB::table('conversations')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)->where('id', $dialog)->first() ?? abort(404);
            if ($member->role === 'member' && ($member->settings['sales_manager'] ?? false) && $conversation->assigned_user_id !== $actor->id) {
                abort(404);
            }
            abort_unless($conversation->mode === 'manager' && $conversation->assigned_user_id !== null, 422, 'Сначала заберите диалог.');
            $channel = $conversation->channel_account_id ? DB::table('channel_accounts')->where('organization_id', $context->organizationId)
                ->where('brand_id', $context->brandId)->where('id', $conversation->channel_account_id)->value('type') : null;
            $live = config('features.E5-01.defaults.manager_telegram_delivery', false) && $channel === 'telegram';
            $id = (string) Str::ulid();
            $meta = ['delivery' => $live ? 'live' : 'fake'];
            if ($request->hasFile('attachment')) {
                $file = $request->file('attachment');
                $meta['attachment_name'] = mb_substr($file->getClientOriginalName(), 0, 255);
                $meta['attachment_path'] = $file->store('seller-dialog-attachments/'.$context->brandId, 'local');
            }
            DB::table('messages')->insert(['id' => $id, 'organization_id' => $context->organizationId, 'brand_id' => $context->brandId,
                'conversation_id' => $dialog, 'role' => 'manager', 'text' => trim($input['text'] ?? '') !== '' ? trim($input['text']) : 'Вложение', 'meta' => json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
            DeliverManagerMessage::dispatch($context->organizationId, $context->brandId, $dialog, $id)->afterCommit();
        });

        return redirect('/seller/dialogs/'.$dialog)->with('status', $live ? 'Сообщение поставлено в очередь отправки в Telegram.' : 'Сообщение поставлено в тестовую очередь.');
    }

    public function attachment(Request $request, string $dialog, string $message): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $actor, TenantContext $context, $brand, Membership $member) use ($dialog, $message): mixed {
            Gate::forUser($actor)->authorize('seller.dialogs.read');
            abort_unless(app(SellerModuleAccess::class)->enabled($context->organizationId, $context->brandId), 404);
            $conversation = DB::table('conversations')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)->where('id', $dialog)->first() ?? abort(404);
            if ($member->role === 'member' && ($member->settings['sales_manager'] ?? false) && $conversation->assigned_user_id !== $actor->id && ! ($conversation->mode === 'manager' && $conversation->assigned_user_id === null)) {
                abort(404);
            }
            $row = DB::table('messages')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)->where('conversation_id', $dialog)->where('id', $message)->first(['meta']) ?? abort(404);
            $meta = json_decode($row->meta ?: '{}', true);
            abort_unless(is_array($meta) && is_string($meta['attachment_path'] ?? null) && is_string($meta['attachment_name'] ?? null) && Storage::disk('local')->exists($meta['attachment_path']), 404);

            return Storage::disk('local')->download($meta['attachment_path'], $meta['attachment_name']);
        });
    }
}
