<?php

namespace App\Seller\Conversations;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;
use PDOException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Discard internal exception chains, which may contain message text or SQL bindings. */
final class PrivateOperation
{
    public static function run(#[\SensitiveParameter] Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (ModelNotFoundException) {
            throw new ModelNotFoundException;
        } catch (HttpExceptionInterface $exception) {
            throw HttpException::fromStatusCode($exception->getStatusCode(), 'Операция с диалогом не выполнена.', headers: self::safeHeaders($exception->getHeaders()));
        } catch (DeadlockException $exception) {
            // Nested Laravel transactions rethrow detected concurrency errors as
            // DeadlockException, whose original message may include SQL bindings.
            $code = (string) $exception->getCode() === '40001' ? 40001 : 0;
            throw new DeadlockException($code === 40001 ? 'Database serialization failure.' : 'deadlock detected', $code);
        } catch (QueryException $exception) {
            $previous = self::safePdo($exception);
            $class = $exception instanceof UniqueConstraintViolationException ? UniqueConstraintViolationException::class : QueryException::class;
            throw new $class('redacted', '[redacted]', [], $previous);
        } catch (PDOException $exception) {
            throw self::safePdo($exception);
        } catch (LogicException $exception) {
            // This exact constant is used by concurrent access checks; never copy
            // arbitrary exception messages, which may contain user content.
            $message = $exception->getMessage() === 'Active brand is required.'
                ? 'Active brand is required.' : 'Операция с диалогом не выполнена.';
            throw new LogicException($message);
        } catch (Throwable) {
            throw new RuntimeException('Операция с диалогом не выполнена.');
        }
    }

    private static function safePdo(#[\SensitiveParameter] PDOException $exception): SafePdoException
    {
        $candidate = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $state = preg_match('/\A[A-Z0-9]{5}\z/D', $candidate) ? $candidate : 'HY000';

        return new SafePdoException($state);
    }

    private static function safeHeaders(#[\SensitiveParameter] array $headers): array
    {
        $safe = [];
        foreach ($headers as $name => $value) {
            if (! is_string($name) || (! is_string($value) && ! is_int($value))) {
                continue;
            }
            $value = (string) $value;
            if (strcasecmp($name, 'Retry-After') === 0 && preg_match('/\A[0-9]{1,10}\z/D', $value)) {
                $safe['Retry-After'] = $value;
            } elseif (strcasecmp($name, 'Retry-After') === 0 && self::validHttpDate($value)) {
                $safe['Retry-After'] = $value;
            } elseif (strcasecmp($name, 'WWW-Authenticate') === 0 && strcasecmp($value, 'Bearer') === 0) {
                $safe['WWW-Authenticate'] = 'Bearer';
            }
        }

        return $safe;
    }

    private static function validHttpDate(#[\SensitiveParameter] string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \G\M\T', $value, new DateTimeZone('UTC'));

        return $date !== false && $date->format('D, d M Y H:i:s \G\M\T') === $value;
    }
}
