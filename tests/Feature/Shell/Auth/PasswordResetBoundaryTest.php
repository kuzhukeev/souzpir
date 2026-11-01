<?php

namespace Tests\Feature\Shell\Auth;

use App\Shell\Auth\PasswordResetExchangeBoundary;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Tests\TestCase;

final class PasswordResetBoundaryTest extends TestCase
{
    public function test_recovery_credentials_are_transformed_before_downstream_code(): void
    {
        $token = str_repeat('a', 64);
        $email = 'Synthetic.Person@example.test';
        $request = Request::create('/reset-password/%65xchange?synthetic=query', 'POST', [], [], [],
            ['CONTENT_TYPE' => 'application/json'], json_encode(['token' => $token, 'email' => $email], JSON_THROW_ON_ERROR));
        $response = app(PasswordResetExchangeBoundary::class)->handle($request, function (Request $safe) use ($token, $email) {
            self::assertSame('{}', $safe->getContent());
            self::assertSame([], $safe->all());
            self::assertSame('', $safe->server->get('QUERY_STRING'));
            self::assertSame(hash('sha256', $token), $safe->attributes->get('password.reset_token_hash'));
            self::assertSame(mb_strtolower($email), Crypt::decryptString($safe->attributes->get('password.reset_email_encrypted')));
            $serialized = json_encode([$safe->all(), $safe->attributes->all(), $safe->server->all()], JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString($token, $serialized);
            self::assertStringNotContainsString($email, $serialized);
            self::assertStringNotContainsString(mb_strtolower($email), $serialized);

            return response()->json(['ok' => true]);
        });
        self::assertSame(200, $response->getStatusCode());
    }

    public function test_invalid_payloads_are_scrubbed_and_rejected_before_the_next_handler(): void
    {
        $secret = str_repeat('b', 64);
        foreach ([
            ['application/json', '{"token":"'.$secret.'"'],
            ['application/json', json_encode(['token' => $secret, 'email' => 'person@example.test', 'padding' => str_repeat('x', 5000)], JSON_THROW_ON_ERROR)],
            ['text/plain', json_encode(['token' => $secret, 'email' => 'person@example.test'], JSON_THROW_ON_ERROR)],
            ['application/json', json_encode(['token' => 'not-a-token', 'email' => 'person@example.test'], JSON_THROW_ON_ERROR)],
            ['application/json', json_encode(['token' => $secret, 'email' => 'invalid-email'], JSON_THROW_ON_ERROR)],
        ] as [$type, $body]) {
            $request = Request::create('/reset-password/exchange', 'POST', [], [], [], ['CONTENT_TYPE' => $type], $body);
            $response = app(PasswordResetExchangeBoundary::class)->handle($request, static function () {
                self::fail('Invalid recovery input reached a downstream handler.');
            });
            self::assertSame(404, $response->getStatusCode());
            self::assertSame('{}', $request->getContent());
            self::assertSame([], $request->all());
            self::assertNull($request->attributes->get('password.reset_token_hash'));
            self::assertStringNotContainsString($secret, $response->getContent());
            self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
    }

    public function test_downstream_exception_and_global_pipeline_see_only_scrubbed_input(): void
    {
        $token = str_repeat('c', 64);
        $email = 'person@example.test';
        $request = Request::create('/reset-password/exchange', 'POST', [], [], [],
            ['CONTENT_TYPE' => 'application/json'], json_encode(['token' => $token, 'email' => $email], JSON_THROW_ON_ERROR));
        $middleware = app(Kernel::class)->getGlobalMiddleware();
        self::assertContains(PasswordResetExchangeBoundary::class, $middleware);
        try {
            app(PasswordResetExchangeBoundary::class)->handle($request, static function (Request $safe): never {
                throw new RuntimeException(json_encode([$safe->getContent(), $safe->all(), $safe->attributes->all()], JSON_THROW_ON_ERROR));
            });
            self::fail('The synthetic exception was not raised.');
        } catch (RuntimeException $error) {
            self::assertStringNotContainsString($token, (string) $error);
            self::assertStringNotContainsString($email, (string) $error);
            self::assertStringNotContainsString($token, json_encode($error->getTrace(), JSON_THROW_ON_ERROR));
        }
    }

    public function test_global_http_pipeline_scrubs_exchange_without_changing_other_requests(): void
    {
        $token = str_repeat('d', 64);
        $email = 'pipeline@example.test';
        $request = Request::create('/reset-password/exchange', 'POST', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode(['token' => $token, 'email' => $email], JSON_THROW_ON_ERROR));
        app(Kernel::class)->handle($request);
        self::assertSame('{}', $request->getContent());
        self::assertSame([], $request->all());
        self::assertSame(hash('sha256', $token), $request->attributes->get('password.reset_token_hash'));
        self::assertStringNotContainsString($email, json_encode($request->attributes->all(), JSON_THROW_ON_ERROR));

        $other = Request::create('/unrelated', 'POST', [], [], [],
            ['CONTENT_TYPE' => 'application/json'], '{"value":"preserved"}');
        app(PasswordResetExchangeBoundary::class)->handle($other, function (Request $safe) {
            self::assertSame('{"value":"preserved"}', $safe->getContent());

            return response()->json(['ok' => true]);
        });
    }
}
