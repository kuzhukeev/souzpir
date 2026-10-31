<?php

namespace App\Shell\Auth\Http;

use App\Models\Brand;
use App\Models\Membership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class ReadyController
{
    public function show(Request $request): Response|RedirectResponse
    {
        $organizationId = config('features.E0-02.defaults.organization_id');
        $canOpenJournal = false;
        if (is_string($organizationId) && Str::isUlid($organizationId)) {
            $organization = DB::table('organizations')->where('id', $organizationId)->where('status', 'active')->first();
            $membership = Membership::withoutGlobalScopes()->where('organization_id', $organizationId)
                ->where('user_id', $request->user()->id)->where('status', 'active')->first();
            if ($organization && $membership) {
                $brands = Brand::withoutGlobalScopes()->where('organization_id', $organizationId)->get(['id', 'status']);
                if ($organization->owner_user_id === $request->user()->id && $membership->role === 'owner' && $brands->isEmpty()) {
                    return redirect()->route('onboarding.show');
                }
                $role = $membership->role;
                $isEditor = $role === 'member' && ($membership->settings['sales_manager'] ?? false) === false;
                if (in_array($role, ['owner', 'admin'], true) || $isEditor) {
                    $canOpenJournal = $brands->contains(fn (Brand $brand): bool => $brand->status === 'active' && $membership->permitsBrand($brand->id));
                }
            }
        }

        return Inertia::render('Shell/Auth/Ready', [
            'mfaEnabled' => (bool) $request->user()->mfa_confirmed_at,
            'canOpenJournal' => $canOpenJournal,
        ]);
    }
}
