<?php

namespace App\Seller\Settings\Policy;

use App\Models\User;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Shell\Support\FakeFieldRequestDelivery;
use App\Shell\Support\SupportActions;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class FieldRequestController
{
    public function store(Request $request): mixed
    {
        $input = $request->validate(['description' => ['required', 'string', 'min:10', 'max:9500'], 'submission_key' => ['required', 'ulid'], 'brand_id' => ['prohibited'], 'organization_id' => ['prohibited']]);
        $brandName = app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant, object $brand): string {
            Gate::forUser($user)->authorize('seller.settings.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return $brand->name;
        });
        $submission = app(SupportActions::class)->create($request, ['subject' => 'other', 'module' => 'seller',
            'body' => 'Изменение полей бренда «'.$brandName.'»: '.trim($input['description']), 'support_access' => false,
            'submission_key' => strtoupper($input['submission_key'])]);
        if ($submission->created) {
            app(FakeFieldRequestDelivery::class)->send($submission->ticket);
        }

        return back()->with('status', 'Тестовая заявка сохранена в поддержке. Письмо не отправлено.');
    }
}
