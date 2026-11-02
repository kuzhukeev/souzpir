<?php

namespace Tests\Feature\Seller\Knowledge\Escalation;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\Import\YamlKnowledgeImporter;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class KnowledgeImportEscalationTest extends TenancyTestCase
{
    public function test_yaml_preview_publishes_only_for_selected_brand_and_rejects_bad_upload(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $files = [];
        foreach (YamlKnowledgeImporter::FILES as $name) {
            $files[] = UploadedFile::fake()->createWithContent($name.'.yaml', file_get_contents(base_path('tests/Feature/Seller/Knowledge/Fixtures/data/'.$name.'.yaml')));
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->postJson('/seller/knowledge/imports/yaml/preview', ['files' => [UploadedFile::fake()->createWithContent('bad.yaml', 'bad')]])->assertUnprocessable();
        $this->postJson('/seller/knowledge/imports/yaml/preview', ['files' => $files])->assertOk()->assertJsonStructure(['sections' => YamlKnowledgeImporter::FILES]);
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->post('/seller/knowledge/imports/yaml/publish')->assertNotFound();
        $this->post('/brand/switch', ['brand_id' => $brand->id])->assertRedirect();
        $this->post('/seller/knowledge/imports/yaml/publish')->assertRedirect();
        self::assertNotNull(DB::table('brands')->where('id', $brand->id)->value('active_knowledge_release_id'));
        self::assertNull(DB::table('brands')->where('id', $other->id)->value('active_knowledge_release_id'));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->postJson('/seller/knowledge/imports/yaml/preview', ['files' => $files])->assertForbidden();
    }

    public function test_zip_preview_accepts_ten_sections(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $path = tempnam(sys_get_temp_dir(), 'knowledge-');
        $zip = new \ZipArchive;
        self::assertTrue($zip->open($path, \ZipArchive::OVERWRITE));
        foreach (YamlKnowledgeImporter::FILES as $name) {
            $zip->addFile(base_path('tests/Feature/Seller/Knowledge/Fixtures/data/'.$name.'.yaml'), $name.'.yaml');
        }
        $zip->close();
        $file = new UploadedFile($path, 'knowledge.zip', 'application/zip', null, true);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->postJson('/seller/knowledge/imports/yaml/preview', ['files' => [$file]])->assertOk()->assertJsonStructure(['sections' => YamlKnowledgeImporter::FILES]);
        unlink($path);
    }

    public function test_command_import_repeat_and_escalation_editor(): void
    {
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $dir = base_path('tests/Feature/Seller/Knowledge/Fixtures/data');
        $this->artisan('seller:knowledge-import', ['brand' => $brand->id, 'dir' => $dir])->assertSuccessful();
        $first = DB::table('brands')->where('id', $brand->id)->value('active_knowledge_release_id');
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        $repo = app(KnowledgeRepository::class);
        $initial = app(TenantContextRunner::class)->run($tenant, fn () => $repo->published($tenant)->toArray());
        self::assertTrue(collect($initial['fewshot']['examples'])->contains(fn ($item) => $item['tag'] === 'open_greeting_widget'));
        $export = app(TenantContextRunner::class)->run($tenant, fn () => app(YamlKnowledgeImporter::class)->export($tenant, $first));
        $exportDir = storage_path('app/knowledge-export-'.Str::random(8));
        mkdir($exportDir, 0700, true);
        foreach ($export as $name => $yaml) {
            file_put_contents($exportDir.DIRECTORY_SEPARATOR.$name, $yaml);
        }
        $this->artisan('seller:knowledge-import', ['brand' => $brand->id, 'dir' => $exportDir])->assertSuccessful();
        $second = DB::table('brands')->where('id', $brand->id)->value('active_knowledge_release_id');
        self::assertNotSame($first, $second);
        $after = app(TenantContextRunner::class)->run($tenant, fn () => $repo->published($tenant)->toArray());
        self::assertEquals($initial, $after);
        self::assertNotNull(DB::table('knowledge_releases')->where('id', $first)->where('status', 'published')->first());
        $draft = DB::table('knowledge_releases')->where('brand_id', $brand->id)->where('status', 'draft')->first();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/knowledge/escalation')->assertOk()->assertInertia(fn ($page) => $page->component('Seller/Knowledge/Escalation/Index')->where('draft.id', $draft->id));
        $reasons = $after['escalation']['reasons'];
        $reasons[0]['title'] = 'Передать руководителю';
        $this->put('/seller/knowledge/escalation', ['version' => $draft->version, 'defaults' => ['response_time_text' => 'завтра', 'assignee' => 'manager_on_duty'], 'reasons' => $reasons])->assertRedirect();
        $edited = app(TenantContextRunner::class)->run($tenant, fn () => $repo->forRelease($tenant, $draft->id, true)->escalation());
        self::assertSame('Передать руководителю', $edited['reasons'][0]['title']);
        $this->put('/seller/knowledge/escalation', ['version' => $draft->version, 'defaults' => ['response_time_text' => '', 'assignee' => 'x'], 'reasons' => []])->assertSessionHasErrors('defaults.response_time_text');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/knowledge/escalation')->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/seller/knowledge/escalation')->assertOk()->assertInertia(fn ($page) => $page->where('draft', null));
    }
}
