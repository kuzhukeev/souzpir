<?php

namespace App\Providers\Features;

use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;

class FeatureRouteCollection extends RouteCollection
{
    /** @var list<Route> */
    private array $registeredRoutes = [];

    public function add(Route $route): Route
    {
        // Keep overwritten routes too: fluent ->name() runs after Router::add().
        $this->registeredRoutes[] = $route;

        return parent::add($route);
    }

    /** @return list<Route> */
    public function registeredRoutes(): array
    {
        return $this->registeredRoutes;
    }
}
