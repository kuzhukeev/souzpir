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
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ReactionController
{
    public function save(Request $request): mixed
    {
        $input = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'tag' => ['required', Rule::in(['decline_off_topic', 'decline_off_topic_second', 'decline_injection', 'opt_out_ack', 'negative_sentiment'])],
            'phrases' => ['present', 'array', 'max:20'],
            'phrases.*' => ['required', 'string', 'max:2000'],
        ]);
        app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($input): void {
            Gate::forUser($user)->authorize('seller.settings.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);
            $id = DB::table('knowledge_releases')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('status', 'draft')->value('id') ?? abort(404);
            $repo = app(KnowledgeRepository::class);
            $data = $repo->forRelease($tenant, $id, true)->toArray();
            if ($input['tag'] === 'negative_sentiment') {
                if (count($input['phrases']) !== 1 || trim($input['phrases'][0]) === '') {
                    throw ValidationException::withMessages(['phrases' => 'Укажите одну идею ответа.']);
                }
                $found = false;
                foreach ($data['escalation']['reasons'] ?? [] as $index => $reason) {
                    if (($reason['id'] ?? null) === 'negative_sentiment') {
                        $data['escalation']['reasons'][$index]['message_idea'] = trim($input['phrases'][0]);
                        $found = true;
                        break;
                    }
                }
                if (! $found) {
                    throw ValidationException::withMessages(['tag' => 'Сначала добавьте причину раздражения в базе знаний.']);
                }
                $repo->saveDraft($tenant, $id, (int) $input['version'], $data);

                return;
            }
            $all = $data['fewshot']['examples'] ?? [];
            $old = array_values(array_filter($all, fn ($item) => ($item['tag'] ?? null) === $input['tag']));
            $remaining = array_values(array_filter($all, fn ($item) => ($item['tag'] ?? null) !== $input['tag']));
            foreach ($input['phrases'] as $index => $phrase) {
                $remaining[] = array_replace($old[$index] ?? ['tag' => $input['tag'], 'client_type' => 'all', 'confidence' => 1, 'source' => '', 'situation' => ''], ['text' => trim($phrase)]);
            }
            $data['fewshot']['examples'] = $remaining;
            $repo->saveDraft($tenant, $id, (int) $input['version'], $data);
        });

        return back()->with('status', 'Фразы сохранены в черновике.');
    }
}
