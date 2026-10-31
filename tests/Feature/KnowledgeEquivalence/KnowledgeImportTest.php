<?php

namespace Tests\Feature\KnowledgeEquivalence;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\Import\YamlKnowledgeImporter;
use App\Seller\Knowledge\KnowledgeNotReady;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Tests\Feature\Seller\Knowledge\Fixtures\PrototypeKnowledge;
use Tests\Feature\Tenancy\TenancyTestCase;

final class KnowledgeImportTest extends TenancyTestCase
{
    private function sourcePath(): string
    {
        return base_path('tests/Feature/Seller/Knowledge/Fixtures/data');
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(fn ($item) => $this->canonical($item), $value);
    }

    public function test_committed_fixtures_match_original_normalized_source_hashes(): void
    {
        $hashes = json_decode(file_get_contents(base_path('tests/fixtures/knowledge/prototype-sha256.json')), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(10, $hashes);
        foreach ($hashes as $name => $expected) {
            $bytes = file_get_contents($this->sourcePath().DIRECTORY_SEPARATOR.$name);
            self::assertIsString($bytes);
            self::assertSame($expected, hash('sha256', str_replace("\r\n", "\n", $bytes)), $name);
        }
    }

    public function test_original_namespaces_roundtrip_through_published_release_and_export(): void
    {
        [$owner, $org, $brand, , $member] = $this->cabinet();
        $ctx = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        app(TenantContextRunner::class)->run($ctx, function () use ($ctx): void {
            $importer = app(YamlKnowledgeImporter::class);
            $prototype = new PrototypeKnowledge($this->sourcePath(), 0);
            $snapshot = $importer->importAndPublish($ctx, $this->sourcePath());
            $export = $importer->export($ctx, $snapshot->releaseId());
            foreach (PrototypeKnowledge::FILES as $file) {
                self::assertSame($this->canonical($prototype->all($file)), $this->canonical($snapshot->all($file)), $file);
                self::assertSame($this->canonical($prototype->all($file)), $this->canonical(Yaml::parse($export[$file.'.yaml'])), $file.' export');
            }
            self::assertSame($snapshot->releaseId(), app(KnowledgeRepository::class)->published($ctx)->releaseId());
            $draftId = DB::table('knowledge_releases')->where('status', 'draft')->value('id');
            try {
                $importer->export($ctx, $draftId);
                self::fail('Draft export accepted.');
            } catch (KnowledgeNotReady) {
                self::assertNotNull($draftId);
            }
            self::assertGreaterThan(0, DB::table('kn_facts')->where('source', 'import')->count());
            self::assertSame(0, DB::table('kn_facts')->where('source', '!=', 'import')->count());
            try {
                $importer->importAndPublish($ctx, $this->sourcePath());
                self::fail('Existing draft overwritten.');
            } catch (HttpException $exception) {
                self::assertSame(409, $exception->getStatusCode());
            }
            self::assertSame($snapshot->releaseId(), app(KnowledgeRepository::class)->published($ctx)->releaseId());
        });
    }

    public function test_missing_or_invalid_input_does_not_create_any_release(): void
    {
        [$owner, $org, $brand, , $member] = $this->cabinet();
        $ctx = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        app(TenantContextRunner::class)->run($ctx, function () use ($ctx): void {
            try {
                app(YamlKnowledgeImporter::class)->importAndPublish($ctx, $this->sourcePath().'/does-not-exist');
                self::fail('Missing input accepted.');
            } catch (InvalidArgumentException) {
                self::assertSame(0, DB::table('knowledge_releases')->count());
            }
            $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'knowledge-'.bin2hex(random_bytes(8));
            mkdir($dir);
            foreach (YamlKnowledgeImporter::FILES as $file) {
                copy($this->sourcePath().DIRECTORY_SEPARATOR.$file.'.yaml', $dir.DIRECTORY_SEPARATOR.$file.'.yaml');
            }
            file_put_contents($dir.DIRECTORY_SEPARATOR.'facts.yaml', 'not-an-array');
            try {
                app(YamlKnowledgeImporter::class)->importAndPublish($ctx, $dir);
                self::fail('Invalid input accepted.');
            } catch (InvalidArgumentException) {
                self::assertSame(0, DB::table('knowledge_releases')->count());
            }
            file_put_contents($dir.DIRECTORY_SEPARATOR.'facts.yaml', "base: &base [one, two]\ncopy: *base\n");
            try {
                app(YamlKnowledgeImporter::class)->importAndPublish($ctx, $dir);
                self::fail('YAML aliases accepted.');
            } catch (ParseException) {
                self::assertSame(0, DB::table('knowledge_releases')->count());
            }
        });
    }

    public function test_error_after_writing_facts_rolls_back_the_whole_import(): void
    {
        [$owner, $org, $brand, , $member] = $this->cabinet();
        $ctx = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        $auditCount = DB::table('audit_logs')->count();
        $enabled = true;
        $updates = 0;
        DB::listen(function ($query) use (&$enabled, &$updates): void {
            if ($enabled && str_contains($query->sql, 'update "kn_facts"') && ++$updates === 2) {
                $enabled = false;
                throw new \RuntimeException('Synthetic failure after facts were written.');
            }
        });
        app(TenantContextRunner::class)->run($ctx, function () use ($ctx, $brand, $auditCount): void {
            try {
                app(YamlKnowledgeImporter::class)->importAndPublish($ctx, $this->sourcePath());
                self::fail('Synthetic failure was not triggered.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('Synthetic failure', $exception->getMessage());
            }
            self::assertSame(0, DB::table('knowledge_releases')->count());
            self::assertSame(0, DB::table('kn_facts')->count());
            self::assertSame($auditCount, DB::table('audit_logs')->count());
            self::assertNull(DB::table('brands')->where('id', $brand->id)->value('active_knowledge_release_id'));
        });
    }

    public function test_viewer_cannot_import_or_replace_knowledge(): void
    {
        [, $org, $brand] = $this->cabinet();
        [$viewer, $membership] = $this->member($org, 'viewer', [$brand->id]);
        $ctx = new TenantContext($org->id, $brand->id, $viewer->id, $membership->id);
        app(TenantContextRunner::class)->run($ctx, function () use ($ctx): void {
            try {
                app(YamlKnowledgeImporter::class)->importAndPublish($ctx, $this->sourcePath());
                self::fail('Viewer import accepted.');
            } catch (HttpException $exception) {
                self::assertSame(403, $exception->getStatusCode());
                self::assertSame(0, DB::table('knowledge_releases')->count());
            }
        });
    }
}
