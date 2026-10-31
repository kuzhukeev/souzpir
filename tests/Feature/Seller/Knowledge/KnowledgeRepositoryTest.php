<?php

namespace Tests\Feature\Seller\Knowledge;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\DatabaseKnowledgeRepository;
use App\Seller\Knowledge\KnowledgeNotReady;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Seller\Knowledge\Fixtures\PrototypeKnowledge;
use Tests\Feature\Tenancy\TenancyTestCase;

final class KnowledgeRepositoryTest extends TenancyTestCase
{
    private function data(): array
    {
        $source = new PrototypeKnowledge(__DIR__.'/Fixtures/data', 0);
        $data = [];
        foreach (PrototypeKnowledge::FILES as $file) {
            $data[$file] = $source->all($file);
        }

        return $data;
    }

    private function normalized(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(fn ($item) => $this->normalized($item), $value);
    }

    public function test_publish_immutable_copy_and_rollback_preserve_all_prototype_data(): void
    {
        [$owner,$org,$brand,, $membership] = $this->cabinet();
        $ctx = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        $source = $this->data();
        app(TenantContextRunner::class)->run($ctx, function () use ($ctx, $source): void {
            $store = app(DatabaseKnowledgeRepository::class);
            try {
                app(KnowledgeRepository::class)->published($ctx);
                self::fail('Missing published release accepted');
            } catch (KnowledgeNotReady) {
            }
            $draft = $store->createDraft($ctx, $source);
            $this->assertSame($this->normalized($source), $this->normalized($store->forRelease($ctx, $draft['id'], true)->toArray()));
            $first = $store->publish($ctx, $draft['id'], 1);
            self::assertSame($first->releaseId(), app(KnowledgeRepository::class)->published($ctx)->releaseId());
            self::assertSame($this->normalized($source), $this->normalized(app(KnowledgeRepository::class)->published($ctx)->toArray()));
            try {
                DB::transaction(fn () => DB::table('kn_persona')->where('release_id', $first->releaseId())->update(['data' => '{}']));
                self::fail('Published data changed');
            } catch (QueryException $e) {
                self::assertStringContainsString('immutable', $e->getMessage());
            }
            $changed = $source;
            $changed['persona']['name'] = 'Тестовый редактор';
            $store->saveDraft($ctx, $draft['id'], 2, $changed);
            $second = $store->publish($ctx, $draft['id'], 3);
            self::assertNotSame($first->releaseId(), $second->releaseId());
            self::assertSame('Тестовый редактор', app(KnowledgeRepository::class)->published($ctx)->persona()['name']);
            $store->rollback($ctx, $first->releaseId());
            self::assertSame($first->releaseId(), app(KnowledgeRepository::class)->published($ctx)->releaseId());
            self::assertSame($this->normalized($source), $this->normalized(app(KnowledgeRepository::class)->published($ctx)->toArray()));
            self::assertSame(5, DB::table('audit_logs')->where('organization_id', $ctx->organizationId)->count());
        });
    }

    public function test_stale_edit_and_foreign_brand_fail_closed(): void
    {
        [$owner,$org,$brand,$other,$membership] = $this->cabinet();
        $ctx = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        $id = app(TenantContextRunner::class)->run($ctx, function () use ($ctx) {
            $store = app(DatabaseKnowledgeRepository::class);
            $draft = $store->createDraft($ctx, $this->data());
            $store->publish($ctx, $draft['id'], 1);
            try {
                $store->saveDraft($ctx, $draft['id'], 1, $this->data());
                self::fail('Stale version accepted');
            } catch (HttpException $e) {
                self::assertSame(409, $e->getStatusCode());
            }

            return $draft['id'];
        });
        $otherCtx = new TenantContext($org->id, $other->id, $owner->id, $membership->id);
        app(TenantContextRunner::class)->run($otherCtx, function () use ($otherCtx, $id) {
            try {
                app(KnowledgeRepository::class)->forRelease($otherCtx, $id, true);
                self::fail('Foreign release exposed');
            } catch (HttpException $e) {
                self::assertSame(404, $e->getStatusCode());
            }
        });
    }

    public function test_inactive_fact_and_source_survive_save_publish_and_rollback(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        $ctx = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(TenantContextRunner::class)->run($ctx, function () use ($ctx): void {
            $store = app(DatabaseKnowledgeRepository::class);
            $draft = $store->createDraft($ctx, $this->data());
            $fact = DB::table('kn_facts as f')->join('kn_fact_groups as g', 'g.id', '=', 'f.group_id')->where('g.release_id', $draft['id'])->orderBy('g.position')->orderBy('f.position')->select('f.*', 'g.key')->first();
            $version = $store->setFact($ctx, $draft['id'], $fact->id, 1, $fact->text, 'inactive', 'import');
            $all = $store->forRelease($ctx, $draft['id'], true)->toArray();
            $this->assertContains($fact->text, $all['facts'][0]['facts']);
            $saved = $store->saveDraft($ctx, $draft['id'], $version, $all);
            $this->assertDatabaseHas('kn_facts', ['id' => $fact->id, 'logical_id' => $fact->logical_id, 'source' => 'import', 'status' => 'inactive']);
            $first = $store->publish($ctx, $draft['id'], $saved['version']);
            $this->assertNotContains($fact->text, $first->all('facts')[0]['facts']);
            $publishedFact = DB::table('kn_facts as f')->join('kn_fact_groups as g', 'g.id', '=', 'f.group_id')->where('g.release_id', $first->releaseId())->where('f.logical_id', $fact->logical_id)->select('f.*')->first();
            self::assertNotNull($publishedFact);
            self::assertSame('inactive', $publishedFact->status);
            self::assertSame('import', $publishedFact->source);
            self::assertNotSame($fact->id, $publishedFact->id);
            $next = $store->setFact($ctx, $draft['id'], $fact->id, $saved['version'] + 1, $fact->text, 'active', 'import');
            $second = $store->publish($ctx, $draft['id'], $next);
            $this->assertContains($fact->text, $second->all('facts')[0]['facts']);
            $store->rollback($ctx, $first->releaseId());
            $this->assertNotContains($fact->text, app(KnowledgeRepository::class)->published($ctx)->all('facts')[0]['facts']);
            try {
                DB::transaction(fn () => DB::statement('TRUNCATE kn_facts CASCADE'));
                self::fail('Truncation accepted');
            } catch (QueryException $e) {
                self::assertStringContainsString('cannot be truncated', $e->getMessage());
            }
        });
    }

    public function test_editor_uses_ids_for_duplicate_facts_and_rejects_bulk_fact_edits(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        $ctx = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(TenantContextRunner::class)->run($ctx, function () use ($ctx): void {
            $store = app(DatabaseKnowledgeRepository::class);
            $draft = $store->createDraft($ctx, $this->data());
            $editor = $store->draftEditor($ctx, $draft['id']);
            $first = $editor['groups'][0]['facts'][0];
            $key = $editor['groups'][0]['key'];
            $other = $editor['groups'][1]['key'];
            $added = $store->addFact($ctx, $draft['id'], 1, $key, $first['text'], 'url', 'inactive');
            $store->moveFact($ctx, $draft['id'], $added['id'], 2, $other, 0);
            $store->setFact($ctx, $draft['id'], $added['id'], 3, 'Изменённый факт', 'inactive', 'url');
            $edited = $store->draftEditor($ctx, $draft['id']);
            self::assertSame('Изменённый факт', $edited['groups'][1]['facts'][0]['text']);
            self::assertSame('inactive', $edited['groups'][1]['facts'][0]['status']);
            self::assertSame('url', $edited['groups'][1]['facts'][0]['source']);
            $data = $store->forRelease($ctx, $draft['id'], true)->toArray();
            $changed = $data;
            $changed['facts'][0]['facts'][0] = 'Неоднозначная замена';
            try {
                $store->saveDraft($ctx, $draft['id'], 4, $changed);
                self::fail('Bulk fact edit accepted.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('fact editor', $e->getMessage());
            }
            $saved = $store->saveDraft($ctx, $draft['id'], 4, $data);
            $again = $store->draftEditor($ctx, $draft['id']);
            self::assertSame($added['id'], $again['groups'][1]['facts'][0]['id']);
            self::assertSame($first['id'], $again['groups'][0]['facts'][0]['id']);
            $snapshot = $store->publish($ctx, $draft['id'], $saved['version']);
            self::assertNotContains('Изменённый факт', $snapshot->all('facts')[1]['facts']);
            $store->deleteFact($ctx, $draft['id'], $added['id'], $saved['version'] + 1);
            $editor = $store->draftEditor($ctx, $draft['id']);
            self::assertNotContains($added['id'], array_column($editor['groups'][1]['facts'], 'id'));
        });
    }

    public function test_viewer_cannot_read_draft_or_modify_data(): void
    {
        [$owner,$org,$brand,,$membership] = $this->cabinet();
        [$viewer,$viewerMembership] = $this->member($org, 'viewer', [$brand->id]);
        $ownerCtx = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        $viewerCtx = new TenantContext($org->id, $brand->id, $viewer->id, $viewerMembership->id);
        $draft = app(TenantContextRunner::class)->run($ownerCtx, fn () => app(DatabaseKnowledgeRepository::class)->createDraft($ownerCtx, $this->data()));
        app(TenantContextRunner::class)->run($viewerCtx, function () use ($viewerCtx, $draft) {
            try {
                app(KnowledgeRepository::class)->forRelease($viewerCtx, $draft['id'], true);
                self::fail('Viewer read draft');
            } catch (HttpException $e) {
                self::assertSame(403, $e->getStatusCode());
            }
            try {
                app(DatabaseKnowledgeRepository::class)->saveDraft($viewerCtx, $draft['id'], 1, $this->data());
                self::fail('Viewer edited draft');
            } catch (HttpException $e) {
                self::assertSame(403, $e->getStatusCode());
            }
        });
    }
}
