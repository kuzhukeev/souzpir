<?php

namespace App\Seller\Dialogs;

use App\Models\Membership;
use App\Models\User;
use App\Seller\Conversations\Data\ReviewType;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class DialogReviewController
{
    public function store(Request $request, string $dialog, string $message): RedirectResponse
    {
        $input = $request->validate([
            'type' => ['required', Rule::enum(ReviewType::class)],
            'note' => ['nullable', 'string', 'max:2000'],
            'better_text' => ['nullable', 'string', 'max:5000'],
        ]);
        app(ProfileAccess::class)->run($request, function (User $actor, TenantContext $context, $brand, Membership $member) use ($dialog, $message, $input): void {
            Gate::forUser($actor)->authorize('seller.dialogues.operate');
            abort_unless(app(SellerModuleAccess::class)->enabled($context->organizationId, $context->brandId), 404);
            $conversation = DB::table('conversations')->where('organization_id', $context->organizationId)
                ->where('brand_id', $context->brandId)->where('id', $dialog)->first() ?? abort(404);
            $manager = $member->role === 'member' && ($member->settings['sales_manager'] ?? false);
            if ($manager && $conversation->assigned_user_id !== $actor->id) {
                abort(404);
            }
            $exists = DB::table('messages')->where('organization_id', $context->organizationId)
                ->where('brand_id', $context->brandId)->where('conversation_id', $dialog)
                ->where('id', $message)->where('role', 'agent')->exists();
            abort_unless($exists, 404);
            DB::table('message_reviews')->insert([
                'id' => (string) Str::ulid(), 'organization_id' => $context->organizationId,
                'brand_id' => $context->brandId, 'message_id' => $message, 'user_id' => $actor->id,
                'type' => $input['type'], 'note' => $input['note'] ?? null,
                'better_text' => $input['better_text'] ?? null, 'status' => 'new',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return redirect('/seller/dialogs/'.$dialog);
    }
}
