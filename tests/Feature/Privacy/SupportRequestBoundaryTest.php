<?php

namespace Tests\Feature\Privacy;

use App\Privacy\SupportRequestBoundary;
use App\Shell\Auth\InvitationSecretBoundary;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Middleware\EnsureDeferredCallbacksRun;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Throwable;

final class SupportRequestBoundaryTest extends TestCase
{
    private string|false $oldIgnoreArgs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oldIgnoreArgs = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        config(['app.debug' => true]);
        DangerousSupportException::$reportCalled = false;
        DangerousSupportException::$renderCalled = false;
        DangerousSupportException::$responsableCalled = false;
        DangerousSupportException::$innerCalled = false;
    }

    protected function tearDown(): void
    {
        unset($_SERVER['LARAVEL_OCTANE']);
        if ($this->oldIgnoreArgs !== false) {
            ini_set('zend.exception_ignore_args', $this->oldIgnoreArgs);
        }
        parent::tearDown();
    }

    public function test_boundary_runs_before_framework_request_processing(): void
    {
        $middleware = app(HttpKernel::class)->getGlobalMiddleware();
        self::assertSame(SupportRequestBoundary::class, $middleware[0]);
        self::assertCount(1, array_filter($middleware, fn ($entry) => $entry === SupportRequestBoundary::class));
        $inertia = array_search(EnsureDeferredCallbacksRun::class, $middleware, true);
        $invitation = array_search(InvitationSecretBoundary::class, $middleware, true);
        $size = array_search(ValidatePostSize::class, $middleware, true);
        self::assertIsInt($inertia);
        self::assertIsInt($invitation);
        self::assertIsInt($size);
        self::assertLessThan($invitation, $inertia);
        self::assertLessThan($size, $invitation);
        $this->get('/up')->assertOk();
        self::assertSame($middleware, app(HttpKernel::class)->getGlobalMiddleware());
    }

    public function test_pre_controller_failure_never_reports_or_renders_private_exception(): void
    {
        $marker = 'SUPPORT_PRIVATE_MARKER_123';
        $messages = [];
        $handled = null;
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$messages): void {
            $messages[] = [$event->message, $event->context];
        });
        Event::listen(RequestHandled::class, function (RequestHandled $event) use (&$handled): void {
            $handled = [
                'input' => $event->request->all(),
                'raw' => $event->request->getContent(),
                'query' => $event->request->server->get('QUERY_STRING'),
                'uri' => $event->request->server->get('REQUEST_URI'),
                'exception' => property_exists($event->response, 'exception') ? $event->response->exception : null,
            ];
        });
        Route::post('/help/support', fn () => response('unreachable'))->middleware(ExplodingSupportMiddleware::class);

        $response = $this->post('/help/support?search='.$marker, [
            'body' => $marker,
            'attachments' => [UploadedFile::fake()->createWithContent($marker.'.txt', $marker)],
        ], ['Accept' => 'application/json']);

        $response->assertStatus(500)->assertHeader('Cache-Control', 'no-store, private');
        self::assertStringNotContainsString($marker, $response->getContent());
        self::assertStringNotContainsString($marker, json_encode($messages, JSON_PARTIAL_OUTPUT_ON_ERROR));
        self::assertFalse(DangerousSupportException::$reportCalled);
        self::assertFalse(DangerousSupportException::$renderCalled);
        self::assertFalse(DangerousSupportException::$responsableCalled);
        self::assertFalse(DangerousSupportException::$innerCalled);
        self::assertSame('0', ini_get('zend.exception_ignore_args'));
        self::assertNotNull($handled);
        self::assertSame([], $handled['input']);
        self::assertSame('', $handled['raw']);
        self::assertSame('', $handled['query']);
        self::assertSame('/help/support', $handled['uri']);
        self::assertNull($handled['exception']);
    }

    public function test_post_controller_sql_error_hides_bindings_and_previous_with_debug_enabled(): void
    {
        $marker = 'SUPPORT_SQL_PRIVATE_MARKER';
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = [$event->message, $event->context];
        });
        Route::post('/help/support', function (Request $request) use ($marker): never {
            self::assertSame('1', ini_get('zend.exception_ignore_args'));
            throw new QueryException('test', 'select ?', [$request->input('body')], new RuntimeException($marker));
        });

        $response = $this->postJson('/help/support', ['body' => $marker]);
        $response->assertStatus(500);
        self::assertStringNotContainsString($marker, $response->getContent());
        self::assertStringNotContainsString($marker, json_encode($logged, JSON_PARTIAL_OUTPUT_ON_ERROR));
        self::assertSame('0', ini_get('zend.exception_ignore_args'));
    }

    public function test_fixed_validation_messages_keep_only_allowed_fields(): void
    {
        $marker = 'SUPPORT_VALIDATION_MARKER';
        Route::post('/help/support', fn () => throw ValidationException::withMessages([
            'body' => $marker, 'attachments.0' => $marker, 'other_'.$marker => $marker,
        ]));

        $response = $this->postJson('/help/support', ['body' => $marker]);
        $response->assertStatus(422)->assertJsonPath('errors.body.0', 'Проверьте это поле.');
        self::assertSame(['Проверьте это поле.'], $response->json('errors')['attachments.0']);
        self::assertStringNotContainsString($marker, $response->getContent());
        self::assertArrayNotHasKey('other_'.$marker, $response->json('errors'));
    }

    public function test_known_localized_validation_text_is_preserved_via_fixed_whitelist(): void
    {
        Route::post('/help/support', fn () => throw ValidationException::withMessages([
            'body' => ['Описание должно содержать минимум 10 символов.', 'PRIVATE_UNTRUSTED_MESSAGE'],
            'subject' => 'Поле «Тема» обязательно.',
        ]));
        $response = $this->postJson('/help/support');
        $response->assertStatus(422)->assertJsonPath('errors.body.0', 'Описание должно содержать минимум 10 символов.')
            ->assertJsonPath('errors.subject.0', 'Поле «Тема» обязательно.');
        self::assertStringNotContainsString('PRIVATE_UNTRUSTED_MESSAGE', $response->getContent());
    }

    public function test_html_validation_redirect_does_not_flash_private_input(): void
    {
        Route::middleware('web')->post('/help/support', fn () => throw ValidationException::withMessages(['body' => 'PRIVATE_FORM_VALUE']));
        $response = $this->post('/help/support', ['body' => 'PRIVATE_FORM_VALUE']);
        $response->assertRedirect('/help/support')->assertSessionHasErrors('body');
        self::assertFalse(session()->hasOldInput('body'));
        self::assertStringNotContainsString('PRIVATE_FORM_VALUE', serialize(session()->all()));
    }

    public function test_known_statuses_are_preserved_without_exception_messages_or_headers(): void
    {
        Route::post('/help/support/{id}/access/revoke', fn (string $id) => throw new HttpException((int) $id, 'SECRET_STATUS_TEXT', null, ['X-Private' => 'SECRET_HEADER']));
        foreach ([400, 401, 403, 404, 405, 409, 413, 419, 422, 429] as $status) {
            $response = $this->postJson('/help/support/'.$status.'/access/revoke');
            $response->assertStatus($status)->assertHeaderMissing('X-Private');
            self::assertStringNotContainsString('SECRET_', $response->getContent());
        }
    }

    public function test_framework_auth_tenant_csrf_and_validation_exceptions_are_safe(): void
    {
        Route::post('/help/support/{kind}', function (string $kind): never {
            throw match ($kind) {
                'auth' => new AuthenticationException('PRIVATE_EXCEPTION'),
                'tenant' => new AuthorizationException('PRIVATE_EXCEPTION'),
                'missing' => new ModelNotFoundException('PRIVATE_EXCEPTION'),
                'csrf' => new TokenMismatchException('PRIVATE_EXCEPTION'),
                'validation' => ValidationException::withMessages(['body' => 'PRIVATE_EXCEPTION']),
                'size' => new PostTooLargeException('PRIVATE_EXCEPTION'),
                'throttle' => new ThrottleRequestsException('PRIVATE_EXCEPTION'),
            };
        });
        foreach (['auth' => 401, 'tenant' => 403, 'missing' => 404, 'csrf' => 419, 'validation' => 422, 'size' => 413, 'throttle' => 429] as $kind => $status) {
            $response = $this->postJson('/help/support/'.$kind, ['body' => 'PRIVATE_EXCEPTION']);
            $response->assertStatus($status);
            self::assertStringNotContainsString('PRIVATE_EXCEPTION', $response->getContent());
        }
    }

    public function test_responsable_without_render_is_never_called_for_support(): void
    {
        Route::post('/help/support', fn () => throw new ResponsibleOnlySupportException);
        ResponsibleOnlySupportException::$called = false;
        $this->postJson('/help/support', ['body' => 'PRIVATE'])->assertStatus(500);
        self::assertFalse(ResponsibleOnlySupportException::$called);
    }

    public function test_custom_http_exception_status_hook_is_not_called(): void
    {
        Route::post('/help/support', fn () => throw new DangerousHttpException);
        DangerousHttpException::$called = false;
        $this->postJson('/help/support', ['body' => 'PRIVATE'])->assertStatus(500);
        self::assertFalse(DangerousHttpException::$called);
    }

    public function test_decoded_path_is_protected_and_unrelated_request_uses_normal_handler(): void
    {
        Route::post('/help/support', fn () => throw new DangerousSupportException);
        Route::post('/unrelated-support', fn () => throw new DangerousSupportException);
        $this->postJson('/help%2Fsupport')->assertStatus(500);
        self::assertFalse(DangerousSupportException::$reportCalled);
        self::assertFalse(DangerousSupportException::$renderCalled);

        $this->postJson('/unrelated-support')->assertStatus(418);
        self::assertTrue(DangerousSupportException::$reportCalled);
        self::assertTrue(DangerousSupportException::$renderCalled);
        self::assertSame('0', ini_get('zend.exception_ignore_args'));
    }

    public function test_octane_requires_pre_enabled_setting_and_never_changes_process_ini(): void
    {
        $_SERVER['LARAVEL_OCTANE'] = '1';
        Route::post('/help/support', fn () => response()->json(['ok' => true]));
        $this->postJson('/help/support', ['body' => 'SYNTHETIC'])->assertStatus(503);
        self::assertSame('0', ini_get('zend.exception_ignore_args'));
        ini_set('zend.exception_ignore_args', '1');
        $this->postJson('/help/support', ['body' => 'SYNTHETIC'])->assertOk();
        self::assertSame('1', ini_get('zend.exception_ignore_args'));
    }
}

final class ResponsibleOnlySupportException extends RuntimeException implements Responsable
{
    public static bool $called = false;

    public function toResponse($request): Response
    {
        self::$called = true;

        return response('unsafe', 418);
    }
}

final class DangerousHttpException extends HttpException
{
    public static bool $called = false;

    public function __construct()
    {
        parent::__construct(403);
    }

    public function getStatusCode(): int
    {
        self::$called = true;

        return 403;
    }
}

final class ExplodingSupportMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->input('body')) {
            throw new DangerousSupportException;
        }

        return $next($request);
    }
}

final class DangerousSupportException extends RuntimeException implements Responsable
{
    public static bool $reportCalled = false;

    public static bool $renderCalled = false;

    public static bool $responsableCalled = false;

    public static bool $innerCalled = false;

    public function getInnerException(): ?Throwable
    {
        self::$innerCalled = true;

        return null;
    }

    public function report(): bool
    {
        self::$reportCalled = true;

        return false;
    }

    public function render(): Response
    {
        self::$renderCalled = true;

        return response('dangerous exception rendering', 418);
    }

    public function toResponse($request): Response
    {
        self::$responsableCalled = true;

        return response('dangerous responsable rendering', 418);
    }
}
