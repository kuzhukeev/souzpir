<?php

namespace App\Shell\Team\Http;

use App\Models\Membership;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\SessionBrandSelection;
use App\Shell\Notifications\RecipientPolicy;
use App\Shell\Team\TeamMemberProjection;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

final class TeamMemberController
{
    public function __invoke(Request $request, string $membership): Response
    {
        Gate::authorize('team.read');
        $member = Membership::query()->with('user:id,name,email,phone')
            ->findOrFail($membership, ['id', 'organization_id', 'user_id', 'role', 'status', 'brand_ids', 'settings']);
        Gate::authorize('view', $member);
        $data = TeamMemberProjection::forCurrentActor()->project($member);
        $data['phone'] = $member->user?->phone;

        $activityUrl = Gate::allows('journal.read') && Route::has('journal.index')
            ? route('journal.index', ['actor' => $member->user_id], false)
            : null;

        $actor = Membership::query()->findOrFail(app(TenantContext::class)->membershipId);
        $unrestricted = in_array($actor->role, ['owner', 'admin'], true) && $actor->brand_ids === [];
        $controlled = $unrestricted || ($member->brand_ids !== [] && array_diff($member->brand_ids, $actor->brand_ids) === []);
        $revision = app(AuthSessionRegistry::class)->withinActive($request, function () use ($request): string {
            $expected = $request->attributes->get('tenant.brand_revision');
            $selection = app(SessionBrandSelection::class)->current($request);
            $ctx = app(TenantContext::class);
            abort_unless(is_string($expected) && (string) $selection->brand_revision === $expected && $selection->selected_organization_id === $ctx->organizationId && $selection->selected_brand_id === $ctx->brandId, 409);

            return app(SessionBrandSelection::class)->profileRevision($request, $expected);
        });

        $context = app(TenantContext::class);
        $sharedBrand = $context->brandId && $member->permitsBrand($context->brandId);
        $dialogCount = $sharedBrand && Gate::allows('seller.dialogs.read')
            ? DB::table('conversations')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)
                ->where('assigned_user_id', $member->user_id)->where('mode', 'manager')->count()
            : null;
        $recipients = collect();
        if ($sharedBrand && Gate::allows('team.manage') && Gate::allows('seller.dialogues.operate') && app(SellerModuleAccess::class)->enabled($context->organizationId, $context->brandId)) {
            $recipients = Membership::query()->with('user:id,name')->where('status', 'active')->get()
                ->filter(function ($candidate) use ($context, $member): bool {
                    if (! $candidate->user || $candidate->user_id === $member->user_id || ! $candidate->permitsBrand($context->brandId)) {
                        return false;
                    }
                    $candidateContext = new TenantContext($context->organizationId, $context->brandId, $candidate->user_id, $candidate->id);

                    return app(TenantContextRunner::class)->run($candidateContext, fn () => Gate::forUser($candidate->user)->allows('seller.dialogues.operate'));
                })->map(fn ($candidate) => ['id' => $candidate->user_id, 'name' => $candidate->user->name])->values();
        }
        $receives = [];
        if ($sharedBrand && Gate::allows('team.manage')) {
            foreach (['vision.measurement.completed', 'seller.conversation.handed_off'] as $event) {
                if (! app(RecipientPolicy::class)->permits($member, $context->brandId, $event)) {
                    $receives[$event] = [];

                    continue;
                }
                $settings = DB::table('notification_settings')->where('organization_id', $context->organizationId)
                    ->where('brand_id', $context->brandId)->where('user_id', $member->user_id)->where('event_key', $event)->get(['channel', 'enabled'])->keyBy('channel');
                $receives[$event] = collect(['cabinet', 'email', 'telegram'])->filter(fn ($channel) => isset($settings[$channel]) ? (bool) $settings[$channel]->enabled : $channel === 'cabinet')->values()->all();
            }
        }

        return Inertia::render('Shell/Team/Show', ['member' => $data, 'activityUrl' => $activityUrl, 'dialogCount' => $dialogCount, 'receives' => $receives, 'transferRecipients' => $recipients,
            'editAccess' => Gate::allows('update', $member) && $controlled && $member->status === 'active'
                ? ['brands' => TeamMemberProjection::forCurrentActor()->availableBrands(), 'allBrands' => $unrestricted,
                    'memberAllBrands' => $member->role === 'admin' && $member->brand_ids === [], 'revision' => $revision] : null]);
    }
}
