<?php

namespace App\Shell\Notifications\Http;

use App\Shell\Notifications\ConfigureNotification;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class NotificationSettingsController
{
    public function update(Request $request, ConfigureNotification $action, TenantContext $context): Response
    {
        $values = $request->validate(['event_key' => ['required', 'string', 'max:120'], 'channel' => ['required', 'string'], 'enabled' => ['required', 'boolean'], 'destination' => ['nullable', 'string', 'max:20']]);
        $action->execute($context, $values['event_key'], $values['channel'], (bool) $values['enabled'], $values['destination'] ?? null);

        return response()->noContent();
    }
}
