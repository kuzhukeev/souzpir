<?php

namespace App\Shell\Brands\Http;

use App\Shell\Brands\Actions\BrandAiPolicy;
use App\Shell\Brands\Actions\BrandAiPolicyLocked;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class BrandAiPolicyController
{
    public function __construct(private readonly BrandAiPolicy $policy) {}

    public function update(Request $request, string $brand): RedirectResponse
    {
        $rules = [];
        foreach (BrandAiPolicy::CLASSES as $class) {
            $rules[$class] = ['required', 'boolean'];
        }
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            throw (new ValidationException($validator))->redirectTo(route('brands.settings.show', $brand));
        }
        $allow = array_map(static fn ($value): bool => (bool) $value, $validator->validated());
        try {
            $this->policy->updateFromRequest($request, $brand, $allow);
        } catch (BrandAiPolicyLocked $error) {
            throw ValidationException::withMessages(['knowledge_public' => $error->getMessage()])->redirectTo(route('brands.settings.show', $brand));
        }

        return redirect()->route('brands.settings.show', $brand);
    }
}
