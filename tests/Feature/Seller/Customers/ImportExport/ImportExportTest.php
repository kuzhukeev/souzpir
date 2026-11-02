<?php

namespace Tests\Feature\Seller\Customers\ImportExport;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ImportExportTest extends TenancyTestCase
{
    protected function setUp(): void
    {
        putenv('E5_08_EXPORT_ENABLED=true');
        $_ENV['E5_08_EXPORT_ENABLED'] = $_SERVER['E5_08_EXPORT_ENABLED'] = 'true';
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('E5_08_EXPORT_ENABLED');
        unset($_ENV['E5_08_EXPORT_ENABLED'], $_SERVER['E5_08_EXPORT_ENABLED']);
    }

    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function contact(string $org, string $brand, string $name): string
    {
        $id = (string) Str::ulid();
        DB::table('contacts')->insert(['id' => $id, 'organization_id' => $org, 'brand_id' => $brand,
            'name' => $name, 'phone' => '+70000000000', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function test_merge_moves_conversations_tasks_and_notes_with_audit(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $source = $this->contact($org->id, $brand->id, 'Клиент А');
        $target = $this->contact($org->id, $brand->id, 'Клиент Б');
        $conv = (string) Str::ulid();
        $task = (string) Str::ulid();
        $note = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $conv, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'contact_id' => $source, 'state' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('seller_tasks')->insert(['id' => $task, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $conv, 'type' => 'promise', 'payload' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('seller_customer_notes')->insert(['id' => $note, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'contact_id' => $source, 'author_id' => $owner->id, 'body' => 'Заметка', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/customer-duplicates')->assertOk()->assertInertia(fn ($page) => $page->has('pairs', 1));
        $this->post('/seller/customer-duplicates', ['source' => $source, 'target' => $target])->assertRedirect();
        $this->assertDatabaseHas('contacts', ['id' => $source, 'merged_into_id' => $target]);
        $this->assertDatabaseHas('conversations', ['id' => $conv, 'contact_id' => $target]);
        $this->assertDatabaseHas('seller_tasks', ['id' => $task, 'conversation_id' => $conv]);
        $this->assertDatabaseHas('seller_customer_notes', ['id' => $note, 'contact_id' => $target]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'seller.customer.merged', 'subject_id' => $target]);
    }

    public function test_csv_preview_import_export_brand_and_permissions(): void
    {
        Storage::fake('local');
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $foreign = $this->contact($org->id, $other->id, 'Чужой');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/customer-io/preview', ['file' => UploadedFile::fake()->createWithContent('clients.csv', "Имя;Почта;Город\nАнна;anna@example.test;Тверь\n")])->assertRedirect();
        $id = DB::table('seller_contact_imports')->value('id');
        $this->get('/seller/customer-io')->assertOk()->assertInertia(fn ($page) => $page->where('preview.count', 1));
        $this->post('/seller/customer-io/import', ['id' => $id, 'mapping' => ['name' => 0, 'email' => 1, 'city' => 2]])->assertRedirect();
        $this->assertDatabaseHas('contacts', ['brand_id' => $brand->id, 'name' => 'Анна', 'email' => 'anna@example.test', 'city' => 'Тверь']);
        $imported = DB::table('contacts')->where('brand_id', $brand->id)->where('email', 'anna@example.test')->value('id');
        $this->assertDatabaseMissing('conversations', ['contact_id' => $imported]);
        DB::table('contacts')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'name' => 'Анна', 'city' => 'Тверь', 'created_at' => now(), 'updated_at' => now()]);
        $this->get('/seller/customer-duplicates')->assertOk()->assertInertia(fn ($page) => $page->has('pairs', 1)->where('pairs.0.reason', 'Имя и город'));
        $this->post('/seller/customer-io/import', ['id' => $id, 'mapping' => ['name' => 0]])->assertNotFound();
        $this->post('/seller/customer-duplicates', ['source' => $foreign, 'target' => $foreign])->assertSessionHasErrors('source');
        $local = DB::table('contacts')->where('brand_id', $brand->id)->value('id');
        $this->post('/seller/customer-duplicates', ['source' => $foreign, 'target' => $local])->assertNotFound();
        $this->post('/seller/customer-io/preview', ['file' => UploadedFile::fake()->createWithContent('empty.csv', "Имя\n")])->assertSessionHasErrors('file');
        $this->post('/settings/data/exports', ['module' => 'seller', 'from' => now()->subDay()->format('Y-m-d'), 'to' => now()->format('Y-m-d')])->assertRedirect();
        $this->assertDatabaseHas('data_exports', ['brand_id' => $brand->id, 'module' => 'seller']);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/customer-io')->assertForbidden();
        $this->get('/seller/customer-duplicates')->assertForbidden();
    }
}
