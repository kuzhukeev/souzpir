<?php

namespace App\Shell\Auth\PasswordDelivery;

use App\Models\User;
use Illuminate\Mail\Message;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Explicit operator opt-in; never bound by default. */
final class SmtpPasswordTransport implements PasswordTransport
{
    public function send(#[\SensitiveParameter] User $user, #[\SensitiveParameter] string $token): bool
    {
        if (! config('features.E1-01F.defaults.enabled') || ! config('features.E1-01F.defaults.smtp_enabled') || config('mail.mailers.smtp.transport') !== 'smtp' || ! is_string(config('mail.mailers.smtp.host')) || config('mail.mailers.smtp.host') === '' || ! is_string(config('mail.from.address')) || config('mail.from.address') === '') {
            throw new KnownDeliveryFailure('Password delivery is disabled or unconfigured.');
        }
        try {
            $base = config('app.url');
            if (! is_string($base) || filter_var($base, FILTER_VALIDATE_URL) === false) {
                throw new KnownDeliveryFailure('Unsafe password recovery URL.');
            }
            $base = rtrim($base, '/');
            $parts = parse_url($base);
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = $parts['host'] ?? null;
            if (! is_array($parts) || ! is_string($host) || $host === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
                || ($scheme !== 'https' && ! ($scheme === 'http' && app()->environment('local') && strtolower($host) === 'souzpir.local'))
                || ! ResetToken::valid($token)) {
                throw new KnownDeliveryFailure('Unsafe password recovery URL.');
            }
            $path = route('password.open', absolute: false);
            if ($path !== '/reset-password/open') {
                throw new KnownDeliveryFailure('Unsafe password recovery route.');
            }
            $url = $base.$path;
            $url .= '#'.http_build_query(['token' => $token, 'email' => $user->email], '', '&', PHP_QUERY_RFC3986);
            $html = '<p>Для восстановления доступа перейдите по ссылке:</p><p><a href="'.htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">Восстановить пароль</a></p><p>Ссылка действует ограниченное время.</p>';
        } catch (Throwable) {
            throw new KnownDeliveryFailure('Password message could not be prepared.');
        }
        $receipt = Mail::mailer('smtp')->html($html, function (Message $message) use ($user): void {
            $message->to($user->email)->subject('Восстановление пароля');
        });

        return $receipt instanceof SentMessage;
    }
}
