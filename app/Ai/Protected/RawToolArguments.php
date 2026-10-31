<?php

namespace App\Ai\Protected;

use App\Privacy\Secrets\JsonObjectInspector;
use SensitiveParameter;
use Throwable;

/** Raw JSON shape check only; neither validates a tool schema nor authorizes an effect. */
final class RawToolArguments
{
    public function accepts(#[SensitiveParameter] string $json): bool
    {
        try {
            // The inspector validates JSON and checks all original object keys;
            // its associative return alone cannot distinguish {} from [].
            if (! str_starts_with(ltrim($json, " \t\r\n"), '{')) {
                return false;
            }
            (new JsonObjectInspector)->decode($json);

            return true;
        } catch (Throwable) {
            // Return no parser exception or input to the caller.
            return false;
        }
    }
}
