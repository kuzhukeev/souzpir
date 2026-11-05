<?php

namespace App\Seller\Dialogs;

use App\Models\User;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class MessageTemplatesController
{
    public function update(Request $request, DefaultMessageTemplates $templates): RedirectResponse
    {
        $input = $request->validate([
            'templates' => ['present', 'array', 'max:50'],
            'templates.*.name' => ['required', 'string', 'max:100'],
            'templates.*.text' => ['required', 'string', 'max:5000'],
        ]);

        app(ProfileAccess::class)->run($request, function (User $actor, TenantContext $context) use ($templates, $input): void {
            Gate::forUser($actor)->authorize('seller.dialogues.operate');
            abort_unless(app(SellerModuleAccess::class)->enabled($context->organizationId, $context->brandId), 404);
            $templates->save($context, collect($input['templates'])->map(fn ($template) => [
                'name' => trim($template['name']), 'text' => trim($template['text']),
            ])->values()->all());
        });

        return back();
    }
}
