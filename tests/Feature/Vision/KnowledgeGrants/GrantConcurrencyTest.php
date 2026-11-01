<?php

namespace Tests\Feature\Vision\KnowledgeGrants;

use App\Ai\CanonicalJson;
use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Seller\Knowledge\DatabaseKnowledgeRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\Evidence\Contracts\KnowledgeGate;
use App\Vision\KnowledgeBridge\GrantAuthority;
use App\Vision\KnowledgeBridge\PublishedKnowledgeBridge;
use App\Vision\KnowledgeGrants\DatabaseGrantAuthority;
use App\Vision\KnowledgeGrants\GrantRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Feature\Seller\Knowledge\Fixtures\PrototypeKnowledge;
use Tests\Support\IsolatedDatabaseMigrations;
use Tests\TestCase;

if (! interface_exists(GrantAuthority::class)) {
    require_once __DIR__.'/Fixtures/GrantAuthority.php';
}

final class GrantConcurrencyTest extends TestCase
{
    use IsolatedDatabaseMigrations;

    private function fixture(): array
    {
        self::assertSame(config('runtime.test_database'), DB::connection()->getDatabaseName());
        self::assertNotSame(config('runtime.preview_database'), DB::connection()->getDatabaseName());
        $actor = User::create(['name' => 'Owner', 'email' => 'grant-race@example.test', 'password' => 'test-only-password'])->fresh();
        $org = Organization::create(['name' => 'Cabinet', 'owner_user_id' => $actor->id]);
        [$brand, $membership] = app(TenantContextRunner::class)->run(new TenantContext($org->id, source: 'job'), fn () => [
            Brand::create(['name' => 'Brand']), Membership::create(['user_id' => $actor->id, 'role' => 'owner']),
        ]);
        $ctx = new TenantContext($org->id, $brand->id, $actor->id, $membership->id, 'job');

        return app(TenantContextRunner::class)->run($ctx, function () use ($ctx): array {
            $source = new PrototypeKnowledge(base_path('tests/Feature/Seller/Knowledge/Fixtures/data'), 0);
            $data = [];
            foreach (PrototypeKnowledge::FILES as $file) {
                $data[$file] = $source->all($file);
            }
            $store = app(DatabaseKnowledgeRepository::class);
            $draft = $store->createDraft($ctx, $data);
            $first = $store->publish($ctx, $draft['id'], 1)->releaseId();
            $second = $store->publish($ctx, $draft['id'], 2)->releaseId();
            DB::table('brands')->where('id', $ctx->brandId)->update(['settings' => json_encode(['ai_data_policy' => ['knowledge_public' => ['any']]], JSON_THROW_ON_ERROR)]);
            $grant = app(GrantRegistry::class)->issue($ctx, $second, []);

            return [$ctx, $draft['id'], $first, $second, $grant];
        });
    }

    public function test_authority_requires_callers_transaction(): void
    {
        [$ctx, , , $release, $grant] = $this->fixture();
        app(TenantContextRunner::class)->run($ctx, function () use ($ctx, $release, $grant): void {
            self::assertSame(0, DB::transactionLevel());
            try {
                app(DatabaseGrantAuthority::class)->assertActive($ctx, $grant['id'], $release, $grant['policy_checksum']);
                self::fail('Grant gate accepted an unlocked check.');
            } catch (\LogicException $error) {
                self::assertNull($error->getPrevious());
            }
            DB::transaction(fn () => app(DatabaseGrantAuthority::class)->assertActive($ctx, $grant['id'], $release, $grant['policy_checksum']));
            self::assertTrue(true);
        });
    }

    #[DataProvider('changeModes')]
    public function test_gate_lock_serializes_with_revoke_publish_and_rollback(string $mode): void
    {
        [$ctx, $draft, $first, $second, $grant] = $this->fixture();
        $input = new InputStream;
        $worker = $this->worker($input, ['mode' => $mode, 'org' => $ctx->organizationId, 'brand' => $ctx->brandId,
            'actor' => $ctx->actorId, 'membership' => $ctx->membershipId, 'draft' => $draft, 'target' => $first, 'grant' => $grant['id']]);
        try {
            $pid = $this->ready($worker);
            app(TenantContextRunner::class)->run($ctx, function () use ($ctx, $second, $grant, $worker, $input, $pid): void {
                DB::beginTransaction();
                try {
                    app(DatabaseGrantAuthority::class)->assertActive($ctx, $grant['id'], $second, $grant['policy_checksum']);
                    $input->write("go\n");
                    $this->waitUntilBlocked($worker, $pid);
                    self::assertNull(DB::table('vision_knowledge_grants')->where('id', $grant['id'])->value('revoked_at'));
                    DB::commit();
                } finally {
                    if (DB::transactionLevel() > 0) {
                        DB::rollBack();
                    }
                }
            });
            $worker->wait();
            self::assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            self::assertStringContainsString('allowed', $worker->getOutput());
            self::assertNotNull(DB::table('vision_knowledge_grants')->where('id', $grant['id'])->value('revoked_at'));
        } finally {
            if ($worker->isRunning()) {
                $worker->stop();
            }
            $input->close();
        }
    }

    public static function changeModes(): array
    {
        return [['revoke'], ['publish'], ['rollback']];
    }

    public function test_revocation_commits_before_gate_and_old_grant_is_denied(): void
    {
        [$ctx, , , $release, $grant] = $this->fixture();
        $input = new InputStream;
        $worker = $this->worker($input, ['mode' => 'gate', 'org' => $ctx->organizationId, 'brand' => $ctx->brandId,
            'actor' => $ctx->actorId, 'membership' => $ctx->membershipId, 'grant' => $grant['id'],
            'release' => $release, 'checksum' => $grant['policy_checksum']]);
        try {
            $pid = $this->ready($worker);
            app(TenantContextRunner::class)->run($ctx, function () use ($ctx, $grant, $worker, $input, $pid): void {
                DB::beginTransaction();
                try {
                    self::assertTrue(app(GrantRegistry::class)->revoke($ctx, $grant['id']));
                    $input->write("go\n");
                    $this->waitUntilBlocked($worker, $pid);
                    DB::commit();
                } finally {
                    if (DB::transactionLevel() > 0) {
                        DB::rollBack();
                    }
                }
            });
            $worker->wait();
            self::assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            self::assertStringContainsString('denied', $worker->getOutput());
        } finally {
            if ($worker->isRunning()) {
                $worker->stop();
            }
            $input->close();
        }
    }

    public function test_bridge_frozen_check_serializes_with_real_revoke(): void
    {
        [$ctx, , , , $grant] = $this->fixture();
        DB::table('brand_modules')->updateOrInsert(['organization_id' => $ctx->organizationId, 'brand_id' => $ctx->brandId, 'module' => 'vision'], ['id' => (string) Str::ulid(), 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        if (! Schema::hasTable('measurements')) {
            Schema::create('measurements', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->ulid('organization_id');
                $table->ulid('brand_id');
                $table->jsonb('profile_snapshot');
                $table->string('profile_checksum', 64);
            });
        }
        [$id, $profile] = app(TenantContextRunner::class)->run($ctx, fn () => DB::transaction(function () use ($ctx): array {
            $profile = app(PublishedKnowledgeBridge::class)->buildProfile($ctx);
            $id = (string) Str::ulid();
            $row = ['id' => $id, 'organization_id' => $ctx->organizationId, 'brand_id' => $ctx->brandId, 'profile_snapshot' => json_encode($profile, JSON_THROW_ON_ERROR), 'profile_checksum' => CanonicalJson::hash($profile)];
            if (Schema::hasColumn('measurements', 'question_set_id')) {
                $set = (string) Str::ulid();
                DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $ctx->organizationId, 'brand_id' => $ctx->brandId, 'version' => 1, 'questions' => '[{"text":"Вопрос"}]', 'size' => 1, 'checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
                $row += ['question_set_id' => $set, 'status' => 'pending', 'configuration' => '{}', 'created_at' => now(), 'updated_at' => now()];
            }
            DB::table('measurements')->insert($row);

            return [$id, $profile];
        }));
        $input = new InputStream;
        $worker = $this->worker($input, ['mode' => 'revoke', 'org' => $ctx->organizationId, 'brand' => $ctx->brandId,
            'actor' => $ctx->actorId, 'membership' => $ctx->membershipId, 'grant' => $grant['id']]);
        try {
            $pid = $this->ready($worker);
            app(TenantContextRunner::class)->run($ctx, function () use ($ctx, $id, $profile, $worker, $input, $pid): void {
                DB::beginTransaction();
                try {
                    app(KnowledgeGate::class)->assertAllowed($ctx, $id, $profile);
                    $input->write("go\n");
                    $this->waitUntilBlocked($worker, $pid);
                    DB::commit();
                } finally {
                    if (DB::transactionLevel() > 0) {
                        DB::rollBack();
                    }
                }
            });
            $worker->wait();
            self::assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            self::assertStringContainsString('allowed', $worker->getOutput());
            app(TenantContextRunner::class)->run($ctx, function () use ($ctx, $id, $profile): void {
                try {
                    app(KnowledgeGate::class)->assertAllowed($ctx, $id, $profile);
                    self::fail('Revoked frozen snapshot accepted.');
                } catch (\LogicException $e) {
                    self::assertSame('The approved knowledge profile is unavailable.', $e->getMessage());
                }
            });
        } finally {
            if ($worker->isRunning()) {
                $worker->stop();
            }
            $input->close();
        }
    }

    private function worker(InputStream $input, array $data): Process
    {
        $worker = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/concurrency-worker.php'], base_path(), ['APP_ENV' => 'testing'], $input, 45);
        $worker->start();
        $input->write(json_encode($data, JSON_THROW_ON_ERROR)."\n");

        return $worker;
    }

    private function ready(Process $worker): int
    {
        $deadline = microtime(true) + 30;
        do {
            $worker->isRunning();
            if (preg_match('/ready:(\d+)/', $worker->getOutput(), $match)) {
                return (int) $match[1];
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        self::fail('Worker not ready: '.$worker->getErrorOutput());
    }

    private function waitUntilBlocked(Process $worker, int $pid): void
    {
        $deadline = microtime(true) + 20;
        do {
            $worker->isRunning();
            if (DB::table('pg_locks')->where('pid', $pid)->where('granted', false)->exists()) {
                self::assertTrue(true);

                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        self::fail('Expected transaction to wait for the grant boundary: '.$worker->getErrorOutput());
    }
}
