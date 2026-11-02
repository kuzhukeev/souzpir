<?php

namespace App\Seller\Settings\Policy;

use App\Models\User;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeNotReady;
use App\Seller\Settings\BrandSellerSettings;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class PolicySettingsController
{
    private function access(Request $request, string $permission, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($callback, $permission) {
            Gate::forUser($user)->authorize($permission);
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return $callback($tenant);
        });
    }

    public function index(Request $request): mixed
    {
        $data = $this->access($request, 'seller.knowledge.read', function (TenantContext $tenant): array {
            $row = BrandSellerSettings::query()->first();
            $followup = array_replace(['enabled' => true, 'first_hours' => 20, 'second_hours' => null, 'max_auto' => 2, 'window' => null, 'texts' => [], 'agreed_text' => ''], $row?->followup ?? []);
            try {
                $policy = app(KnowledgeRepository::class)->published($tenant)->policy();
            } catch (KnowledgeNotReady) {
                $policy = null;
            }

            $draftPolicy = null;
            if (Gate::allows('seller.settings.write')) {
                $draft = DB::table('knowledge_releases')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('status', 'draft')->first(['id', 'version']);
                if ($draft) {
                    $draftPolicy = ['version' => $draft->version, 'policy' => app(KnowledgeRepository::class)->forRelease($tenant, $draft->id, true)->policy()];
                }
            }

            return ['followup' => $followup, 'policy' => $policy, 'canWrite' => Gate::allows('seller.settings.write'), 'draftPolicy' => $draftPolicy];
        });

        return Inertia::render('Seller/Settings/Policy/Index', [...$data, 'status' => session('status')]);
    }

    public function update(Request $request): mixed
    {
        $input = $request->validate(['enabled' => ['required', 'boolean'], 'first_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'second_hours' => ['nullable', 'integer', 'min:1', 'max:720'], 'max_auto' => ['required', 'integer', 'min:0', 'max:10'],
            'window' => ['nullable', 'array'], 'window.start' => ['required_with:window', 'date_format:H:i'], 'window.end' => ['required_with:window', 'date_format:H:i'],
            'texts' => ['present', 'array', 'max:5'], 'texts.*' => ['nullable', 'string', 'max:500'], 'agreed_text' => ['nullable', 'string', 'max:500']]);
        if (($input['window']['start'] ?? null) !== null && $input['window']['start'] === $input['window']['end']) {
            throw ValidationException::withMessages(['window.end' => 'Конец окна должен отличаться от начала.']);
        }
        $this->access($request, 'seller.settings.write', function (TenantContext $tenant) use ($input): void {
            $row = BrandSellerSettings::query()->first() ?? new BrandSellerSettings;
            $row->followup = ['enabled' => (bool) $input['enabled'], 'first_hours' => (int) $input['first_hours'],
                'second_hours' => isset($input['second_hours']) ? (int) $input['second_hours'] : null,
                'max_auto' => (int) $input['max_auto'], 'window' => $input['window'] ?? null,
                'texts' => array_values(array_filter($input['texts'], fn ($text) => is_string($text) && trim($text) !== '')),
                'agreed_text' => $input['agreed_text'] ?? ''];
            $row->save();
            app(AuditWriter::class)->record($tenant, new AuditEntry('seller.followup.settings.updated', 'brand_seller_settings', $row->id));
        });

        return back()->with('status', 'Настройки напоминаний сохранены.');
    }
}
