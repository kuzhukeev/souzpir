<?php

namespace App\Seller\Settings\Policy;

use App\Models\User;
use App\Seller\Engine\LeadState;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ClientFieldsController
{
    public function save(Request $request): mixed
    {
        $input = $request->validate([
            'version' => ['required', 'integer', 'min:1'], 'fields' => ['present', 'array', 'max:50'],
            'fields.*.id' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/', 'distinct'],
            'fields.*.name' => ['required', 'string', 'max:100'],
            'fields.*.type' => ['required', Rule::in(['text', 'number', 'select'])],
            'fields.*.options' => ['present', 'array', 'max:30'], 'fields.*.options.*' => ['required', 'string', 'max:100'],
            'fields.*.required' => ['required', 'boolean'], 'fields.*.stage' => ['nullable', Rule::in(LeadState::STAGES)],
        ]);
        foreach ($input['fields'] as $index => $field) {
            if (in_array($field['id'], ['name', 'email', 'messenger'], true)) {
                throw ValidationException::withMessages(["fields.$index.id" => 'Системные контактные поля изменить нельзя.']);
            }
            if (($field['type'] === 'select') !== (count($field['options']) > 0)) {
                throw ValidationException::withMessages(["fields.$index.options" => 'Варианты нужны только для поля со списком.']);
            }
        }
        app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($input): void {
            Gate::forUser($user)->authorize('seller.settings.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);
            $id = DB::table('knowledge_releases')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('status', 'draft')->value('id') ?? abort(404);
            $repository = app(KnowledgeRepository::class);
            $data = $repository->forRelease($tenant, $id, true)->toArray();
            $data['policy']['client_fields'] = $input['fields'];
            $repository->saveDraft($tenant, $id, (int) $input['version'], $data);
        });

        return back()->with('status', 'Поля сохранены в черновике.');
    }
}
