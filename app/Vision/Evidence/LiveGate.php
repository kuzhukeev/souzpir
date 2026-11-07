<?php

namespace App\Vision\Evidence;

/** Shared gate so queued and manual slot processing evaluate evidence under the same condition. */
final class LiveGate
{
    public static function evidenceEnabled(): bool
    {
        return config('runtime.ai_driver', 'fake') !== 'fake' && config('eyon.live_measurements_enabled', false);
    }
}
