<?php

namespace App\Http\Middleware;

use App\Http\PreviewProps;
use App\Providers\Features\FeatureRegistry;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $props = parent::share($request);
        if (app()->environment('local', 'testing') && $request->is('preview', 'preview/*', 'dev', 'dev/*')) {
            return [...$props, ...PreviewProps::make()];
        }

        return [...$props, ...app(FeatureRegistry::class)->sharedProps($request)];
    }
}
