<?php

namespace Tests\Unit\Migration;

use App\Migration\OrganizationDryRun;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SQLite3;

final class OrganizationDryRunTest extends TestCase
{
    private string $source;

    private string $target;

    private const ORG = '01J00000000000000000000001';

    private const OTHER = '01J00000000000000000000002';

    private const OWNER = '01J00000000000000000000003';

    private const BRAND = '01J00000000000000000000004';

    protected function setUp(): void
    {
        $this->source = tempnam(sys_get_temp_dir(), 'src');
        $this->target = tempnam(sys_get_temp_dir(), 'dst');
        foreach ([$this->source, $this->target] as $path) {
            $db = new SQLite3($path);
            $db->exec('CREATE TABLE organizations (id TEXT); CREATE TABLE brands (id TEXT, organization_id TEXT); CREATE TABLE users (id TEXT)');
            $db->close();
        }
        $db = new SQLite3($this->source);
        $db->exec("INSERT INTO organizations VALUES ('".self::ORG."'); INSERT INTO brands VALUES ('".self::BRAND."', '".self::ORG."'), ('other-brand', '".self::OTHER."')");
        $db->close();
        $db = new SQLite3($this->target);
        $db->exec("INSERT INTO users VALUES ('".self::OWNER."')");
        $db->close();
    }

    protected function tearDown(): void
    {
        unlink($this->source);
        unlink($this->target);
    }

    public function test_dry_run_counts_only_selected_organization_and_never_changes_copies(): void
    {
        $before = [hash_file('sha256', $this->source), hash_file('sha256', $this->target)];
        $report = (new OrganizationDryRun)->inspect($this->source, $this->target, self::ORG, self::OWNER);
        self::assertSame(['organizations' => 1, 'brands' => 1], $report['source']);
        self::assertSame([], $report['errors']);
        self::assertSame($report['target_before'], $report['target_after']);
        self::assertSame($before, [hash_file('sha256', $this->source), hash_file('sha256', $this->target)]);
    }

    public function test_reports_missing_owner_and_brand_collision(): void
    {
        $db = new SQLite3($this->target);
        $db->exec("DELETE FROM users; INSERT INTO brands VALUES ('".self::BRAND."', '".self::OTHER."')");
        $db->close();
        $report = (new OrganizationDryRun)->inspect($this->source, $this->target, self::ORG, self::OWNER);
        self::assertCount(2, $report['errors']);
        self::assertSame(1, $report['target_before']['brands_with_same_id']);
    }

    public function test_rejects_invalid_identifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new OrganizationDryRun)->inspect($this->source, $this->target, 'invalid', self::OWNER);
    }

    public function test_reports_missing_organization(): void
    {
        $report = (new OrganizationDryRun)->inspect($this->source, $this->target, self::OTHER, self::OWNER);
        self::assertNotEmpty($report['errors']);
    }
}
