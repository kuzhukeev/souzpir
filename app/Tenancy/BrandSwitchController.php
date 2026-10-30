<?php

namespace App\Tenancy;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class BrandSwitchController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate(['brand_id' => ['required', 'string', 'ulid']]);
        $brand = $request->attributes->get('tenant.brands')->firstWhere('id', $data['brand_id']);
        abort_unless($brand, 404);
        $request->session()->put('brand_id', $brand->id);

        // Fixed same-origin destination; no user-supplied redirect URL.
        return redirect('/');
    }
}
