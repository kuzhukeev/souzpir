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
use Illuminate\Validation\ValidationException;

final class SlotOrderController
{
    public function save(Request $request): mixed
    {
        $input = $request->validate(['version' => ['required', 'integer', 'min:1'], 'slot_order' => ['required', 'array'], 'slot_order.*' => ['required', 'array'], 'slot_order.*.*' => ['required', 'string', 'max:100'],
            'max_consecutive_questions' => ['required', 'integer', 'min:1', 'max:5']]);
        app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($input): void {
            Gate::forUser($user)->authorize('seller.settings.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);
            $id = DB::table('knowledge_releases')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('status', 'draft')->value('id') ?? abort(404);
            $repo = app(KnowledgeRepository::class);
            $data = $repo->forRelease($tenant, $id, true)->toArray();
            $old = $data['policy']['slot_order'] ?? [];
            $order = $input['slot_order'];
            $oldTypes = array_keys($old);
            $newTypes = array_keys($order);
            sort($oldTypes);
            sort($newTypes);
            if ($oldTypes !== $newTypes) {
                throw ValidationException::withMessages(['slot_order' => 'Список типов клиентов изменился. Обновите страницу.']);
            }
            foreach ($old as $type => $slots) {
                $before = $slots;
                $after = $order[$type];
                sort($before);
                sort($after);
                if ($before !== $after) {
                    throw ValidationException::withMessages(['slot_order' => 'Разрешено менять только порядок существующих вопросов.']);
                }
            }
            $data['policy']['slot_order'] = $order;
            $data['policy']['limits'] ??= [];
            $data['policy']['limits']['max_consecutive_questions'] = (int) $input['max_consecutive_questions'];
            $repo->saveDraft($tenant, $id, (int) $input['version'], $data);
        });

        return back()->with('status', 'Порядок вопросов сохранён в черновике.');
    }
}
