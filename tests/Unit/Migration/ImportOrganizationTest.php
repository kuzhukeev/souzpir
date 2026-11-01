<?php

namespace Tests\Unit\Migration;

use App\Migration\ImportPreparedOrganization;
use App\Migration\RollbackOrganizationBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use SQLite3;
use Tests\TestCase;

final class ImportOrganizationTest extends TestCase
{
    use RefreshDatabase;

    private const ORG = '01J00000000000000000000001';

    private const BRAND = '01J00000000000000000000002';

    private string $source;

    private string $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'test-only-password'])->id;
        $this->source = tempnam(sys_get_temp_dir(), 'migration');
        $db = new SQLite3($this->source);
        $db->exec('CREATE TABLE organizations (id TEXT, name TEXT); CREATE TABLE brands (id TEXT, organization_id TEXT, name TEXT)');
        $db->exec("INSERT INTO organizations VALUES ('".self::ORG."', 'Организация'); INSERT INTO brands VALUES ('".self::BRAND."', '".self::ORG."', 'Бренд'), ('01J00000000000000000000003', 'other', 'Другой')");
        $db->close();
    }

    protected function tearDown(): void
    {
        unlink($this->source);
        parent::tearDown();
    }

    public function test_import_is_idempotent_and_rollback_removes_only_batch_records(): void
    {
        $importer = new ImportPreparedOrganization;
        $hash = hash_file('sha256', $this->source);
        $first = $importer->import($this->source, self::ORG, $this->owner);
        $second = $importer->import($this->source, self::ORG, $this->owner);
        self::assertTrue($first['created']);
        self::assertFalse($second['created']);
        self::assertSame($first['batch'], $second['batch']);
        self::assertSame(1, DB::table('brands')->count());
        self::assertSame($hash, hash_file('sha256', $this->source));
        (new RollbackOrganizationBatch)->rollback($first['batch']);
        self::assertSame(0, DB::table('organizations')->count());
        self::assertSame(0, DB::table('brands')->count());
        self::assertSame(0, DB::table('migration_organization_batches')->count());
        self::assertTrue(DB::table('users')->where('id', $this->owner)->exists());
    }

    public function test_rollback_rejects_changed_records_and_dependencies(): void
    {
        $batch = (new ImportPreparedOrganization)->import($this->source, self::ORG, $this->owner)['batch'];
        DB::table('brands')->where('id', self::BRAND)->update(['name' => 'Изменён']);
        try {
            (new RollbackOrganizationBatch)->rollback($batch);
            self::fail('Changed brand must block rollback.');
        } catch (LogicException $error) {
            self::assertStringContainsString('изменены', $error->getMessage());
        }
        DB::table('brands')->where('id', self::BRAND)->update(['name' => 'Бренд']);
        DB::table('memberships')->insert(['id' => '01J00000000000000000000004', 'organization_id' => self::ORG, 'user_id' => $this->owner, 'created_at' => now(), 'updated_at' => now()]);
        try {
            (new RollbackOrganizationBatch)->rollback($batch);
            self::fail('Dependency must block rollback.');
        } catch (LogicException $error) {
            self::assertStringContainsString('используются', $error->getMessage());
        }
        self::assertSame(1, DB::table('organizations')->count());
        self::assertSame(1, DB::table('brands')->count());
        self::assertSame(1, DB::table('migration_organization_batches')->count());
    }

    public function test_rejects_unknown_owner_and_changed_copy_without_mutation(): void
    {
        $importer = new ImportPreparedOrganization;
        try {
            $importer->import($this->source, self::ORG, '01J00000000000000000000005');
            self::fail('Unknown owner must be rejected.');
        } catch (LogicException $error) {
            self::assertStringContainsString('Владелец', $error->getMessage());
        }
        self::assertSame(0, DB::table('organizations')->count());
        $importer->import($this->source, self::ORG, $this->owner);
        $db = new SQLite3($this->source);
        $db->exec("UPDATE brands SET name = 'Новое' WHERE id = '".self::BRAND."'");
        $db->close();
        $this->expectException(LogicException::class);
        $importer->import($this->source, self::ORG, $this->owner);
    }

    public function test_console_commands_import_and_rollback_in_test_database(): void
    {
        $this->artisan('migration:import-organization-copy', ['source' => $this->source, 'organization' => self::ORG, 'owner' => $this->owner])->assertSuccessful();
        $batch = DB::table('migration_organization_batches')->value('id');
        self::assertNotEmpty($batch);
        $this->artisan('migration:rollback-organization-copy', ['batch' => $batch])->assertSuccessful();
        self::assertSame(0, DB::table('organizations')->count());
    }

    public function test_refuses_a_non_test_environment(): void
    {
        $this->app['env'] = 'production';
        try {
            $this->expectException(LogicException::class);
            (new ImportPreparedOrganization)->import($this->source, self::ORG, $this->owner);
        } finally {
            $this->app['env'] = 'testing';
        }
    }
}
