<?php

namespace Tests\Feature\Seller\Conversations;

use App\Seller\Conversations\PrivateOperation;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PDOException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\IsolatedDatabaseMigrations;
use Tests\TestCase;

final class PrivateOperationSemanticsTest extends TestCase
{
    use IsolatedDatabaseMigrations;

    public function test_http_status_and_selected_safe_headers_survive_without_private_content(): void
    {
        foreach (['60', 'Wed, 21 Oct 2015 07:28:00 GMT'] as $retry) {
            try {
                PrivateOperation::run(fn () => throw new HttpException(429, 'PRIVATE_HTTP', headers: ['Retry-After' => $retry, 'X-Private' => 'PRIVATE_HEADER']));
                $this->fail('Expected HTTP failure.');
            } catch (HttpException $exception) {
                $this->assertSame(429, $exception->getStatusCode());
                $this->assertSame(['Retry-After' => $retry], $exception->getHeaders());
                $this->assertStringNotContainsString('PRIVATE_', (string) $exception);
                $this->assertNull($exception->getPrevious());
            }
        }

        try {
            PrivateOperation::run(fn () => throw new HttpException(401, 'PRIVATE_HTTP', headers: ['WWW-Authenticate' => 'Bearer']));
            $this->fail('Expected HTTP failure.');
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->getStatusCode());
            $this->assertSame(['WWW-Authenticate' => 'Bearer'], $exception->getHeaders());
        }

        try {
            PrivateOperation::run(fn () => throw new HttpException(401, headers: ['WWW-Authenticate' => 'Bearer realm="PRIVATE_REALM"']));
            $this->fail('Expected HTTP failure.');
        } catch (HttpException $exception) {
            $this->assertSame([], $exception->getHeaders());
        }
    }

    public function test_query_exception_class_and_sqlstate_survive_without_query_details(): void
    {
        $driver = new PDOException('PRIVATE_DRIVER');
        $driver->errorInfo = ['23505', 7, 'PRIVATE_DETAIL'];
        try {
            PrivateOperation::run(fn () => throw new UniqueConstraintViolationException('pgsql', 'select ?', ['PRIVATE_BINDING'], $driver));
            $this->fail('Expected query failure.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertSame('23505', $exception->getCode());
            $this->assertSame('23505', $exception->errorInfo[0]);
            $this->assertSame('[redacted]', $exception->getSql());
            $this->assertSame([], $exception->getBindings());
            $this->assertStringNotContainsString('PRIVATE_', (string) $exception);
            $this->assertStringNotContainsString('PRIVATE_', (string) $exception->getPrevious());
        }
    }

    public function test_outer_laravel_transaction_retries_serialization_and_only_one_write_remains(): void
    {
        DB::statement('CREATE TEMPORARY TABLE seller_retry_fixture (value TEXT NOT NULL)');
        $attempts = 0;
        DB::transaction(function () use (&$attempts): void {
            $attempts++;
            DB::table('seller_retry_fixture')->insert(['value' => 'one']);
            if ($attempts === 1) {
                $driver = new PDOException('PRIVATE_DRIVER');
                $driver->errorInfo = ['40001', null, 'PRIVATE_DETAIL'];
                PrivateOperation::run(fn () => throw new QueryException('pgsql', 'select ?', ['PRIVATE_BINDING'], $driver));
            }
        }, 2);

        $this->assertSame(2, $attempts);
        $this->assertSame(1, DB::table('seller_retry_fixture')->count());
    }

    public function test_nested_deadlock_keeps_retry_signal_without_original_error(): void
    {
        DB::statement('CREATE TEMPORARY TABLE seller_deadlock_fixture (value TEXT NOT NULL)');
        $attempts = 0;
        DB::transaction(function () use (&$attempts): void {
            $attempts++;
            PrivateOperation::run(fn () => DB::transaction(function () use ($attempts): void {
                DB::table('seller_deadlock_fixture')->insert(['value' => 'one']);
                if ($attempts === 1) {
                    $driver = new PDOException('deadlock detected PRIVATE_DRIVER');
                    $driver->errorInfo = ['40P01', null, 'PRIVATE_DETAIL'];
                    throw new QueryException('pgsql', 'select ?', ['PRIVATE_BINDING'], $driver);
                }
            }));
        }, 2);

        $this->assertSame(2, $attempts);
        $this->assertSame(1, DB::table('seller_deadlock_fixture')->count());
    }
}
