<?php

namespace App\Seller\Settings\Techniques;

use App\Models\User;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class TechniquesController
{
    public function index(Request $request, TechniqueEditor $editor): Response
    {
        $data = app(ProfileAccess::class)->run($request, function (User $actor, TenantContext $context) use ($editor): array {
            $this->authorize($actor, $context);

            return $editor->view($context);
        });

        return Inertia::render('Seller/Settings/Techniques/Index', $data);
    }

    public function save(Request $request, TechniqueEditor $editor, string $technique): RedirectResponse
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:0'], 'name' => ['required', 'string', 'max:120'],
            'idea' => ['required', 'string', 'max:1000'], 'examples' => ['required', 'array', 'min:1', 'max:5'],
            'examples.*' => ['required', 'string', 'max:500'], 'max_uses_per_dialog' => ['required', 'integer', 'between:1,10'],
            'enabled' => ['required', 'boolean'], 'is_base' => ['required', 'boolean'],
            'when' => ['present', 'array', 'max:12'], 'not_when' => ['present', 'array', 'max:12'],
            'when.*.field' => ['required', 'string', 'regex:/^(stage|client_type|slots\.[a-z_]+|signals\.[a-z_]+|flags\.[a-z_]+)$/'],
            'not_when.*.field' => ['required', 'string', 'regex:/^(stage|client_type|slots\.[a-z_]+|signals\.[a-z_]+|flags\.[a-z_]+)$/'],
            'when.*.values' => ['required', 'string', 'max:400'], 'not_when.*.values' => ['required', 'string', 'max:400'],
        ]);
        app(ProfileAccess::class)->run($request, function (User $actor, TenantContext $context) use ($editor, $technique, $data): void {
            $this->authorize($actor, $context);
            $editor->save($context, $technique, $data['version'], $data);
        });

        return redirect('/seller/scenario/techniques')->with('status', 'Черновик сохранён. Для применения опубликуйте его.');
    }

    public function quiet(Request $request, TechniqueEditor $editor): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:0']]);
        app(ProfileAccess::class)->run($request, function (User $actor, TenantContext $context) use ($editor, $data): void {
            $this->authorize($actor, $context);
            $editor->quiet($context, $data['version']);
        });

        return redirect('/seller/scenario/techniques')->with('status', 'Небазовые приёмы выключены в черновике.');
    }

    public function publish(Request $request, TechniqueEditor $editor): RedirectResponse
    {
        $data = $request->validate(['release' => ['required', 'ulid'], 'version' => ['required', 'integer', 'min:1']]);
        app(ProfileAccess::class)->run($request, function (User $actor, TenantContext $context) use ($editor, $data): void {
            $this->authorize($actor, $context);
            $editor->publish($context, $data['release'], $data['version']);
        });

        return redirect('/seller/scenario/techniques')->with('status', 'Приёмы опубликованы.');
    }

    private function authorize(User $actor, TenantContext $context): void
    {
        Gate::forUser($actor)->authorize('seller.knowledge.write');
        abort_unless(app(SellerModuleAccess::class)->enabled($context->organizationId, $context->brandId), 404);
    }
}
