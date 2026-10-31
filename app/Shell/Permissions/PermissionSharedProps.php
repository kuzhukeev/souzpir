<?php

namespace App\Shell\Permissions;

use App\Providers\Features\SharedPropsContributor;
use Illuminate\Http\Request;

final class PermissionSharedProps implements SharedPropsContributor
{
    public function share(Request $request): array
    {
        return ['can' => function () use ($request): array {
            $actor = $request->user();
            if (! $actor) {
                return [];
            }

            return app(PermissionEvaluator::class)->all($actor);
        }];
    }
}
