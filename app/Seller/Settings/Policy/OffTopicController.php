<?php

namespace App\Seller\Settings\Policy;

use App\Models\User;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class OffTopicController
{
    public function save(Request $request): mixed
    {
        $input = $request->validate(['version' => ['required', 'integer', 'min:1'], 'off_topic_streak' => ['required', 'integer', 'min:1', 'max:10'],
            'negative_streak' => ['required', 'integer', 'min:1', 'max:10'], 'action' => ['required', 'in:spam,handoff']]);
        app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($input): void {
            Gate::forUser($user)->authorize('seller.settings.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);
            $id = DB::table('knowledge_releases')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('status', 'draft')->value('id') ?? abort(404);
            $repo = app(KnowledgeRepository::class);
            $data = $repo->forRelease($tenant, $id, true)->toArray();
            $data['policy']['limits'] ??= [];
            $data['policy']['limits']['off_topic_streak_silent'] = (int) $input['off_topic_streak'];
            $data['policy']['limits']['negative_streak_escalate'] = (int) $input['negative_streak'];
            $data['policy']['off_topic_action'] = $input['action'];
            $repo->saveDraft($tenant, $id, (int) $input['version'], $data);
        });

        return back()->with('status', 'Правила сохранены в черновике.');
    }
}
