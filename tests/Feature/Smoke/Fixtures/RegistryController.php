<?php

namespace Tests\Feature\Smoke\Fixtures;

class RegistryController
{
    public function __invoke(): string
    {
        return 'fixture';
    }
}
