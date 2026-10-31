<?php

namespace App\Shell\Auth;

final class MfaCode
{
    /** Convert the supplied code to a keyed digest before routing. */
    public static function parse(#[\SensitiveParameter] mixed $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^[0-9]{6}$/D', $value)) {
            return ['kind' => 'totp', 'digest' => self::digest($value)];
        }
        if (preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{8}(?:-[a-f0-9]{8}){3})$/Di', $value)) {
            return ['kind' => 'recovery', 'digest' => self::digest(strtolower(str_replace('-', '', $value)))];
        }

        return null;
    }

    public static function digest(#[\SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', 'mfa-code:'.$code, (string) config('app.key'));
    }

    /** Must be called under the locked user row. */
    public static function matchTotp(#[\SensitiveParameter] string $secret, string $digest, ?int $last): ?int
    {
        $current = intdiv(now()->timestamp, 30);
        foreach ([$current + 1, $current, $current - 1] as $counter) {
            if ($counter >= 0 && ($last === null || $counter > $last)
                && hash_equals(self::digest(app(Totp::class)->at($secret, $counter)), $digest)) {
                return $counter;
            }
        }

        return null;
    }
}
