<?php

namespace App\Seller\Settings\Greetings;

use App\Models\User;
use App\Seller\Settings\BrandSellerSettings;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

final class GreetingsController
{
    private function access(Request $request, string $ability, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($ability, $callback) {
            Gate::forUser($user)->authorize($ability);
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return $callback($tenant);
        });
    }

    public function index(Request $request): mixed
    {
        $greetings = $this->access($request, 'seller.knowledge.read', fn () => BrandSellerSettings::query()->first()?->greetings ?? []);

        return Inertia::render('Seller/Settings/Greetings/Index', ['greetings' => $greetings, 'canWrite' => Gate::allows('seller.settings.write'), 'status' => session('status')]);
    }

    public function update(Request $request): mixed
    {
        $input = $request->validate(['greetings' => ['present', 'array:telegram,widget,widget_catalog,widget_contacts,api,email,whatsapp,after_hours'], 'greetings.telegram' => ['nullable', 'string', 'max:1000'], 'greetings.widget' => ['nullable', 'string', 'max:1000'], 'greetings.widget_catalog' => ['nullable', 'string', 'max:1000'], 'greetings.widget_contacts' => ['nullable', 'string', 'max:1000'], 'greetings.api' => ['nullable', 'string', 'max:1000'], 'greetings.email' => ['nullable', 'string', 'max:1000'], 'greetings.whatsapp' => ['nullable', 'string', 'max:1000'], 'greetings.after_hours' => ['nullable', 'string', 'max:1000']]);
        $this->access($request, 'seller.settings.write', function (TenantContext $tenant) use ($input): void {
            $row = BrandSellerSettings::query()->first() ?? new BrandSellerSettings;
            $row->greetings = collect($input['greetings'])->map(fn ($value) => trim($value ?? ''))->all();
            $row->save();
            app(AuditWriter::class)->record($tenant, new AuditEntry('seller.greetings.updated', 'brand_seller_settings', $row->id));
        });

        return back()->with('status', 'Приветствия сохранены.');
    }
}
