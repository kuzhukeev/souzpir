<?php

namespace App\Shell\Integrations;

use Illuminate\Http\Request;

/** Secret issuance is allowed only over HTTPS or a direct development loopback connection. */
final class SecretTransport
{
    public const MESSAGE = 'Для создания и просмотра секретов откройте кабинет по HTTPS или через localhost на этом компьютере.';

    public static function allows(Request $request): bool
    {
        if ($request->isSecure()) {
            return true;
        }

        return app()->environment('local', 'testing')
            && self::loopbackHost($request->getHost())
            && self::loopbackAddress((string) $request->server('REMOTE_ADDR'))
            && self::loopbackAddress((string) $request->server('SERVER_ADDR'))
            && ! $request->headers->has('Forwarded')
            && ! $request->headers->has('X-Forwarded-For')
            && ! $request->headers->has('X-Forwarded-Host')
            && ! $request->headers->has('X-Forwarded-Proto');
    }

    public static function assertAllowed(Request $request): void
    {
        abort_unless(self::allows($request), 403, self::MESSAGE);
    }

    private static function loopbackAddress(string $value): bool
    {
        return in_array($value, ['127.0.0.1', '::1'], true);
    }

    private static function loopbackHost(string $value): bool
    {
        return in_array(strtolower(trim($value, '[]')), ['localhost', '127.0.0.1', '::1'], true);
    }
}
