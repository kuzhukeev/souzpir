<?php

namespace App\Seller\Conversations;

use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;
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
            throw new HttpException($exception->getStatusCode(), 'Операция с диалогом не выполнена.');
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
}
