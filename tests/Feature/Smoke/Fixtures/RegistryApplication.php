<?php

namespace Tests\Feature\Smoke\Fixtures;

use Illuminate\Foundation\Application;

class RegistryApplication extends Application
{
    public bool $fixtureConfigCached = false;

    public bool $fixtureRoutesCached = false;

    public function configurationIsCached(): bool
    {
        return $this->fixtureConfigCached;
    }

    public function routesAreCached(): bool
    {
        return $this->fixtureRoutesCached;
    }
}
