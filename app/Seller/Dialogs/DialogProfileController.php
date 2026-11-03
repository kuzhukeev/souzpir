<?php

namespace App\Seller\Dialogs;

use App\Models\Membership;
use App\Models\User;
use App\Seller\Engine\LeadState;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class DialogProfileController
{
    public function update(Request $request, string $dialog): RedirectResponse
    {
        $input = $request->validate([
            'summary' => ['nullable', 'string', 'max:5000'],
            'slots' => ['required', 'array:'.implode(',', LeadState::SLOTS)],
            'slots.*' => ['nullable', 'string', 'max:500'],
        ]);
        app(ProfileAccess::class)->run($request, function (User $actor, TenantContext $context, $brand, Membership $member) use ($dialog, $input): void {
            Gate::forUser($actor)->authorize('seller.dialogues.operate');
            abort_unless(app(SellerModuleAccess::class)->enabled($context->organizationId, $context->brandId), 404);
            $query = DB::table('conversations')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)->where('id', $dialog);
            $row = $query->first(['id', 'state', 'assigned_user_id']) ?? abort(404);
            if ($member->role === 'member' && ($member->settings['sales_manager'] ?? false) && $row->assigned_user_id !== $actor->id) {
                abort(404);
            }
            $state = json_decode($row->state ?: '{}', true);
            $state = is_array($state) ? $state : [];
            $state['slots'] = is_array($state['slots'] ?? null) ? $state['slots'] : [];
            foreach ($input['slots'] as $key => $value) {
                $value = trim($value ?? '');
                $state['slots'][$key] = in_array($key, ['categories', 'brands_interest'], true)
                    ? array_values(array_filter(array_map('trim', explode(',', $value)))) : ($value !== '' ? $value : null);
            }
            $query->update(['summary' => $input['summary'] ?? null, 'state' => json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        });

        return redirect('/seller/dialogs/'.$dialog)->with('status', 'Карточка сохранена.');
    }
}
