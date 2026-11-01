<?php

namespace Tests\Unit\Migration;

use App\Migration\PreparedOrganization;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SQLite3;

final class PreparedOrganizationTest extends TestCase
{
    private string $path;

    private const ORG = '01J00000000000000000000001';

    private const BRAND = '01J00000000000000000000002';

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'prepared');
        $db = new SQLite3($this->path);
        $db->exec('CREATE TABLE organizations (id TEXT, name TEXT); CREATE TABLE brands (id TEXT, organization_id TEXT, name TEXT)');
        $db->exec("INSERT INTO organizations VALUES ('".self::ORG."', 'Тест'); INSERT INTO brands VALUES ('".self::BRAND."', '".self::ORG."', 'Бренд'), ('other', 'other', 'Чужой')");
        $db->close();
    }

    protected function tearDown(): void
    {
        unlink($this->path);
    }

    public function test_reads_only_requested_organization_without_modifying_copy(): void
    {
        $before = hash_file('sha256', $this->path);
        $result = (new PreparedOrganization)->read($this->path, self::ORG);
        self::assertSame('Тест', $result['organization']['name']);
        self::assertSame([['id' => self::BRAND, 'name' => 'Бренд']], $result['brands']);
        self::assertSame($before, hash_file('sha256', $this->path));
    }

    public function test_rejects_invalid_brand_name(): void
    {
        $db = new SQLite3($this->path);
        $db->exec("UPDATE brands SET name = '' WHERE id = '".self::BRAND."'");
        $db->close();
        $this->expectException(InvalidArgumentException::class);
        (new PreparedOrganization)->read($this->path, self::ORG);
    }

    public function test_rejects_missing_organization(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PreparedOrganization)->read($this->path, '01J00000000000000000000003');
    }
}
