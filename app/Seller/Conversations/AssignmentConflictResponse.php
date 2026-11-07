<?php

namespace App\Seller\Conversations;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\JsonResponse;
use Throwable;

/** A failed PostgreSQL statement aborts its transaction: never retry it here. */
final class AssignmentConflictResponse
{
    public static function matches(Throwable $exception): bool
    {
        return $exception instanceof QueryException && ($exception->errorInfo[0] ?? null) === '55P03';
    }

    public static function render(QueryException $exception): JsonResponse
    {
        try {
            $connection = DB::connection($exception->getConnectionName());
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack(0);
            }
            // Also handle a transaction begun directly through PDO.
            if ($connection->getPdo()->inTransaction()) {
                $connection->getPdo()->rollBack();
            }
            $status = 409;
            $message = 'Данные сейчас изменяются. Обновите страницу и повторите попытку.';
        } catch (Throwable) {
            // Do not expose the original exception, query, parameters or rollback error.
            try {
                DB::purge($exception->getConnectionName());
            } catch (Throwable) {
                // The response below must remain free of exception details.
            }
            $status = 500;
            $message = 'Не удалось выполнить действие. Повторите попытку позже.';
        }

        return new JsonResponse(['message' => $message], $status, ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }
}
