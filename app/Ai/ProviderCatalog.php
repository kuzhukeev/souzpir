<?php

namespace App\Ai;

use Illuminate\Support\Facades\DB;

/** Config controls support; stored status can disable a provider, not activate planned code. */
final class ProviderCatalog
{
    public function models(): array
    {
        $states = DB::table('ai_providers')->pluck('status', 'code')->all();
        $models = config('features.E0-05.defaults.models', []);
        foreach ($models as &$model) {
            if (($states[$model['provider']] ?? null) === 'planned') {
                $model['status'] = 'planned';
            }
        }
        unset($model);

        return $models;
    }
}
