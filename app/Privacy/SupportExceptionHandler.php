<?php

namespace App\Privacy;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Throwable;

/** Fail closed before framework exception reporters/renderers can inspect support input. */
final class SupportExceptionHandler extends Handler implements ExceptionHandlerContract
{
    public function report(Throwable $e)
    {
        if ($this->supportRequest() !== null) {
            return;
        }

        parent::report($e);
    }

    public function render($request, Throwable $e)
    {
        if (! $request instanceof Request || ! SupportRequestBoundary::applies($request)) {
            return parent::render($request, $e);
        }

        // Do not map/unwrap the exception, call custom render/report hooks, preserve its
        // headers, or attach it to the response. Any of those may reveal private input.
        try {
            $class = get_class($e);
            $status = match ($class) {
                AuthenticationException::class => 401,
                AuthorizationException::class => in_array($e->status(), [403, 404], true) ? $e->status() : 403,
                ModelNotFoundException::class, NotFoundHttpException::class => 404,
                TokenMismatchException::class => 419,
                ValidationException::class, UnprocessableEntityHttpException::class => 422,
                AccessDeniedHttpException::class => 403,
                BadRequestHttpException::class => 400,
                MethodNotAllowedHttpException::class => 405,
                PostTooLargeException::class => 413,
                ThrottleRequestsException::class, TooManyRequestsHttpException::class => 429,
                HttpException::class => in_array($e->getStatusCode(), [400, 401, 403, 404, 405, 409, 413, 419, 422, 429], true) ? $e->getStatusCode() : 500,
                default => 500,
            };
            $fields = $status === 422 && get_class($e) === ValidationException::class
                ? $this->safeValidationFields($e) : [];
        } catch (Throwable) {
            $status = 500;
            $fields = [];
        }

        $message = match ($status) {
            401 => 'Войдите в кабинет, чтобы продолжить.',
            403 => 'Нет доступа к этому действию.',
            404 => 'Обращение не найдено.',
            409 => 'Данные обращения изменились. Проверьте их и повторите попытку.',
            413 => 'Размер обращения превышает допустимый.',
            419 => 'Сессия истекла. Обновите страницу и повторите.',
            422 => 'Проверьте заполнение обращения.',
            429 => 'Слишком много запросов. Попробуйте позже.',
            default => 'Не удалось выполнить действие. Повторите попытку позже.',
        };

        if (! $request->expectsJson() && $status === 401) {
            return new RedirectResponse('/login', 302, ['Cache-Control' => 'no-store, private']);
        }
        if (! $request->expectsJson() && $status === 422 && $request->hasSession() && $request->session()->isStarted()) {
            $bag = new ViewErrorBag;
            $bag->put('default', new MessageBag($fields));
            $request->session()->flash('errors', $bag);

            return new RedirectResponse('/help/support', 302, ['Cache-Control' => 'no-store, private']);
        }

        $data = ['message' => $message];
        if ($status === 422) {
            $data['errors'] = $fields;
        }

        // Symfony response has no withException() hook, so Routing\Pipeline cannot
        // retain the original exception for a later debug listener.
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function supportRequest(): ?Request
    {
        if (! $this->container->bound('request')) {
            return null;
        }
        $request = $this->container->make('request');

        return $request instanceof Request && SupportRequestBoundary::applies($request) ? $request : null;
    }

    private function safeValidationFields(ValidationException $e): array
    {
        if (get_class($e->validator) !== Validator::class) {
            return [];
        }
        $maxFiles = max(1, min(1000, (int) config('features.E1-12.defaults.max_files', 5)));
        $maxFileKb = max(1, min(1048576, (int) config('features.E1-12.defaults.max_file_kb', 5120)));
        $allowed = [
            'subject' => ['Поле «Тема» обязательно.', 'Выберите допустимое значение поля «Тема».'],
            'module' => ['Поле «Модуль» обязательно.', 'Выберите допустимое значение поля «Модуль».'],
            'body' => ['Поле «Описание» обязательно.', 'Поле «Описание» должно быть текстом.', 'Описание должно содержать минимум 10 символов.', 'Описание не должно превышать 10000 символов.'],
            'support_access' => ['Поле «Согласие» обязательно.', 'Выберите, разрешаете ли вы сохранить согласие.'],
            'attachments' => ['Поле «Вложения» должно содержать список файлов.', 'Можно приложить не более '.$maxFiles.' файлов.', 'Не удалось сохранить вложение. Обращение не создано. Повторите попытку.'],
            'attachment' => ['Выберите корректный файл вложения.', 'Не удалось загрузить вложение. Проверьте размер файла.', 'Размер вложения не должен превышать '.$maxFileKb.' КБ.', 'Допустимы файлы JPEG, PNG, WebP, PDF и TXT.', 'Допустимы расширения JPG, JPEG, PNG, WebP, PDF и TXT.'],
        ];
        $fields = [];
        foreach (array_slice($e->errors(), 0, 1002, true) as $key => $messages) {
            $kind = is_string($key) && isset($allowed[$key]) && $key !== 'attachment' ? $key : null;
            if (is_string($key) && preg_match('/^attachments\.([0-9]{1,3})$/D', $key, $match)
                && (int) $match[1] < $maxFiles) {
                $kind = 'attachment';
            }
            if ($kind === null) {
                continue;
            }
            $safe = [];
            foreach (array_slice($messages, 0, 10) as $message) {
                // Return our own literal, only after exact comparison with the fixed
                // whitelist. Never copy arbitrary validator or exception text.
                foreach ($allowed[$kind] as $candidate) {
                    if (is_string($message) && hash_equals($candidate, $message)) {
                        $safe[] = $candidate;
                        break;
                    }
                }
            }
            $fields[$key] = $safe ?: ['Проверьте это поле.'];
        }

        return $fields;
    }
}
