<?php

namespace Tests\Feature\Smoke\Fixtures;

use App\Providers\Features\SharedPropsContributor;
use Illuminate\Http\Request;

class FixtureProps implements SharedPropsContributor
{
    public static int $evaluations = 0;

    public function share(Request $request): array
    {
        return ['fixture_organization' => static function () use ($request): string {
            self::$evaluations++;

            return $request->attributes->get('tenant', 'not-resolved');
        }];
    }
}
