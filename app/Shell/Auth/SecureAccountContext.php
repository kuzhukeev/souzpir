<?php

namespace App\Shell\Auth;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Server counterpart to the browser's secure-context requirement for encrypted history. */
final class SecureAccountContext
{
    public const MESSAGE = 'Для этой операции откройте кабинет по защищённому HTTPS-адресу.';

    public static function allows(Request $request): bool
    {
        return $request->isSecure() || self::loopback($request->getHost());
    }

    public static function validate(Request $request, string $field): void
    {
        if (! self::allows($request)) {
            throw ValidationException::withMessages([$field => self::MESSAGE]);
        }
    }

    public static function assertRead(Request $request): void
    {
        abort_unless(self::allows($request), 403, self::MESSAGE);
    }

    public static function canonicalEmailAllowed(): bool
    {
        $parts = parse_url((string) config('app.url'));
        if (! is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }

        return ($parts['scheme'] ?? null) === 'https'
            || (($parts['scheme'] ?? null) === 'http' && self::loopback($parts['host']));
    }

    private static function loopback(string $host): bool
    {
        return in_array(strtolower(trim($host, '[]')), ['localhost', '127.0.0.1', '::1'], true);
    }
}
