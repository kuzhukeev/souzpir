<?php

namespace Tests\Feature\Shell\Data;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Providers\Features\FeatureRegistry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Data\Contracts\ExportContributor;
use App\Shell\Data\Contracts\ExportQuery;
use App\Shell\Data\Contracts\ExportRecord;
use App\Shell\Data\DataController;
use App\Shell\Data\PruneExports;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class CommitOutcomeFixtureContributor implements ExportContributor
{
    public function module(): string
    {
        return 'seller';
    }

    public function stream(TenantContext $tenant, ExportQuery $query): iterable
    {
        yield new ExportRecord('fixture', ['brand' => $tenant->brandId]);
    }
}

final class ExportCommitOutcomeTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        $expected = config('runtime.test_database');
        if (! app()->environment('testing') || config('database.default') !== 'pgsql'
            || ! is_string($expected) || ! preg_match('/(?:^|_)test(?:_|$)/i', $expected)
            || DB::connection()->getDatabaseName() !== $expected
            || config('runtime.preview_database') === $expected) {
            throw new RuntimeException('Isolated test database required.');
        }
        $actual = DB::selectOne('select current_database() as db, inet_server_addr() as host, rolsuper, rolcreatedb, rolcreaterole from pg_roles where rolname = current_user');
        if ($actual->db !== $expected || (! str_starts_with((string) $actual->host, '127.') && $actual->host !== '::1')
            || $actual->rolsuper || $actual->rolcreatedb || $actual->rolcreaterole) {
            throw new RuntimeException('Isolated test database required.');
        }
    }

    /** @return array{Request, TenantContext} */
    private function fixture(): array
    {
        Storage::fake('local');
        $registry = app(FeatureRegistry::class);
        $property = new \ReflectionProperty(FeatureRegistry::class, 'extensions');
        $property->setValue($registry, array_merge($registry->extensions(), ['export.seller' => CommitOutcomeFixtureContributor::class]));
        $user = User::create(['name' => 'Synthetic', 'email' => Str::random(16).'@example.test', 'password' => 'synthetic-password']);
        $user->refresh();
        $org = Organization::create(['name' => 'Fixture', 'owner_user_id' => $user->id]);
        [$member, $brand] = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $user->id), fn () => [
            Membership::create(['user_id' => $user->id, 'role' => 'owner']), Brand::create(['name' => 'Fixture brand']),
        ]);
        $ctx = new TenantContext($org->id, $brand->id, $user->id, $member->id);
        app()->instance(TenantContext::class, $ctx);
        $session = app('session')->driver('array');
        $session->setId(Str::random(40));
        $session->start();
        $session->put('auth.version', $user->auth_version);
        $request = Request::create('/settings/data/exports', 'POST', ['module' => 'seller', 'from' => '2026-01-01', 'to' => '2026-01-31']);
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $user);
        app(AuthSessionRegistry::class)->issue($request, $user, $user->auth_version);

        return [$request, $ctx];
    }

    public function test_archive_remains_after_real_commit_then_exception_and_cleanup(): void
    {
        [$request, $ctx] = $this->fixture();
        $raised = false;
        Event::listen(TransactionCommitted::class, function () use (&$raised): void {
            if (! $raised && DB::table('data_exports')->exists()) {
                $raised = true;
                throw new RuntimeException('Synthetic post-commit failure');
            }
        });
        try {
            app(DataController::class)->create($request);
            self::fail('Expected post-commit failure.');
        } catch (RuntimeException $error) {
            self::assertSame('Synthetic post-commit failure', $error->getMessage());
        } finally {
            Event::forget(TransactionCommitted::class);
        }
        self::assertTrue($raised);
        $row = DB::table('data_exports')->sole();
        $disk = Storage::disk('local');
        $disk->assertExists($row->path);
        self::assertSame($row->sha256, hash_file('sha256', $disk->path($row->path)));
        touch($disk->path($row->path), now()->subHours(2)->getTimestamp());
        app(PruneExports::class)->pruneOrganization($ctx->requireOrganizationId(), 10, 5000);
        $disk->assertExists($row->path);
        $this->assertDatabaseHas('data_exports', ['id' => $row->id]);
    }

    public function test_unavailable_database_never_causes_a_published_archive_to_be_deleted(): void
    {
        [$request, $ctx] = $this->fixture();
        $oldPort = config('database.connections.pgsql.port');
        $raised = false;
        Event::listen(TransactionCommitted::class, function () use (&$raised): void {
            if (! $raised && DB::table('data_exports')->exists()) {
                $raised = true;
                DB::purge();
                config(['database.connections.pgsql.port' => 1]);
                throw new RuntimeException('Synthetic disconnected post-commit failure');
            }
        });
        try {
            try {
                app(DataController::class)->create($request);
                self::fail('Expected disconnected post-commit failure.');
            } catch (RuntimeException $error) {
                self::assertSame('Synthetic disconnected post-commit failure', $error->getMessage());
            }
            self::assertTrue($raised);
            $disk = Storage::disk('local');
            $archives = $disk->files('data-exports/'.$ctx->requireOrganizationId().'/files');
            self::assertCount(1, $archives);
            $path = $archives[0];
            $disk->assertExists($path);
            touch($disk->path($path), now()->subHours(2)->getTimestamp());
            try {
                app(PruneExports::class)->pruneOrganization($ctx->requireOrganizationId(), 10, 5000);
                self::fail('Cleanup must fail closed when the database is unavailable.');
            } catch (QueryException) {
                $disk->assertExists($path);
            }
        } finally {
            Event::forget(TransactionCommitted::class);
            DB::purge();
            config(['database.connections.pgsql.port' => $oldPort]);
        }
        $row = DB::table('data_exports')->sole();
        self::assertSame($path, $row->path);
        app(PruneExports::class)->pruneOrganization($ctx->requireOrganizationId(), 10, 5000);
        Storage::disk('local')->assertExists($path);
    }

    public function test_rolled_back_archive_is_an_orphan_only_after_grace(): void
    {
        [$request, $ctx] = $this->fixture();
        $audit = Mockery::mock(AuditWriter::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Synthetic rollback'));
        app()->instance(AuditWriter::class, $audit);
        try {
            app(DataController::class)->create($request);
            self::fail('Expected rollback.');
        } catch (RuntimeException $error) {
            self::assertSame('Synthetic rollback', $error->getMessage());
        }
        self::assertSame(0, DB::table('data_exports')->count());
        $disk = Storage::disk('local');
        $archives = $disk->files('data-exports/'.$ctx->requireOrganizationId().'/files');
        self::assertCount(1, $archives);
        $path = $archives[0];
        app(PruneExports::class)->pruneOrganization($ctx->requireOrganizationId(), 10, 5000);
        $disk->assertExists($path);
        touch($disk->path($path), now()->subHours(2)->getTimestamp());
        app(PruneExports::class)->pruneOrganization($ctx->requireOrganizationId(), 10, 5000);
        $disk->assertMissing($path);
    }
}
