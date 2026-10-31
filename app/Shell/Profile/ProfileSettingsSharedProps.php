<?php

namespace App\Shell\Profile;

use App\Providers\Features\SharedPropsContributor;
use Illuminate\Http\Request;

final class ProfileSettingsSharedProps implements SharedPropsContributor
{
    public function share(Request $request): array
    {
        return ['profileSettingsAvailable' => static fn (): bool => app(ProfileSettingsAvailability::class)->ready()];
    }
}
