<?php

namespace App\Shell\Notifications;

use App\Shell\Notifications\Contracts\NotificationDispatcher;
use App\Shell\Notifications\Jobs\ProcessNotificationEvent;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDOException;
use RuntimeException;
use SensitiveParameter;
use Throwable;

final class OutboxDispatcher implements NotificationDispatcher
{
    public function enqueue(#[SensitiveParameter] DomainEvent $event): void
    {
        if ($this->store($event)) {
            ProcessNotificationEvent::dispatch($event->envelope['event_id'])->afterCommit();
        }
    }

    private function store(#[SensitiveParameter] DomainEvent $event): bool
    {
        try {
            return $this->storeInternal($event);
        } catch (Throwable $exception) {
            throw $this->sanitized($exception);
        }
    }

    private function storeInternal(#[SensitiveParameter] DomainEvent $event): bool
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('An event must be enqueued within the business transaction.');
        }
        $e = $event->envelope;
        $data = json_encode($e, JSON_THROW_ON_ERROR);
        $inserted = DB::table('outbox_events')->insertOrIgnore([
            'id' => $e['event_id'], 'organization_id' => $e['organization_id'], 'brand_id' => $e['brand_id'],
            'envelope' => $data, 'created_at' => now(),
        ]);
        if (! $inserted) {
            $existing = DB::table('outbox_events')->where('id', $e['event_id'])->value('envelope');
            if (json_decode($existing, true, 512, JSON_THROW_ON_ERROR) != $e) {
                throw new LogicException('Event identifier already has different data.');
            }

            return false;
        }

        return true;
    }

    private function safeDatabaseError(#[SensitiveParameter] PDOException $exception): SafeOutboxPdoException
    {
        $candidate = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $state = preg_match('/\A[A-Z0-9]{5}\z/D', $candidate) ? $candidate : 'HY000';

        return new SafeOutboxPdoException($state);
    }

    /** Do not retain the original exception: its JSON/SQL trace can contain the event. */
    private function sanitized(#[SensitiveParameter] Throwable $exception): Throwable
    {
        if ($exception instanceof DeadlockException) {
            $code = (string) $exception->getCode() === '40001' ? 40001 : 0;

            return new DeadlockException($code === 40001 ? 'Database serialization failure.' : 'deadlock detected', $code);
        }
        if ($exception instanceof QueryException) {
            $class = $exception instanceof UniqueConstraintViolationException ? UniqueConstraintViolationException::class : QueryException::class;

            return new $class('redacted', '[redacted]', [], $this->safeDatabaseError($exception));
        }
        if ($exception instanceof PDOException) {
            return $this->safeDatabaseError($exception);
        }
        if ($exception instanceof LogicException) {
            $safe = ['An event must be enqueued within the business transaction.', 'Event identifier already has different data.'];

            return new LogicException(in_array($exception->getMessage(), $safe, true) ? $exception->getMessage() : 'Outbox operation could not be completed.');
        }

        return new RuntimeException('Outbox operation could not be completed.');
    }
}
