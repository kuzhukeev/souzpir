<?php

namespace Tests\Feature\Shell\Journal;

use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class JournalNamesTest extends TenancyTestCase
{
    public function test_contact_name_and_module_are_used_in_journal_and_csv(): void
    {
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        $contact = (string) Str::ulid();
        $foreign = (string) Str::ulid();
        foreach ([[$brand->id, $contact, 'Покупатель А'], [$other->id, $foreign, 'Чужой покупатель']] as [$brandId, $id, $name]) {
            DB::table('contacts')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brandId,
                'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
        $context = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(TenantContextRunner::class)->run($context, function () use ($context, $contact, $foreign): void {
            app(AuditWriter::class)->record($context, new AuditEntry('seller.customer.merged', 'contact', $contact));
            app(AuditWriter::class)->record($context, new AuditEntry('seller.customer.merged', 'contact', $foreign));
        });

        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $rows = collect($this->get('/journal')->assertOk()->inertiaProps('rows.data'));
        self::assertSame('Покупатель А', $rows->firstWhere('subject_id', $contact)['subject_name']);
        self::assertSame('/seller/customers/'.$contact, $rows->firstWhere('subject_id', $contact)['subject_url']);
        self::assertSame('ИИ-продавец', $rows->firstWhere('subject_id', $contact)['module_name']);
        self::assertSame('Объединены клиенты', $rows->firstWhere('subject_id', $contact)['action_label']);
        self::assertSame($foreign, $rows->firstWhere('subject_id', $foreign)['subject_name']);
        self::assertNull($rows->firstWhere('subject_id', $foreign)['subject_url']);

        $csv = $this->get('/journal/export')->assertOk()->streamedContent();
        self::assertStringContainsString('Покупатель А', $csv);
        self::assertStringContainsString('ИИ-продавец', $csv);
        self::assertStringContainsString('Объединены клиенты', $csv);
        self::assertStringNotContainsString('Чужой покупатель', $csv);

        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->flushSession()->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/journal')->assertForbidden();
    }

    public function test_knowledge_object_names_and_action_filter_labels_are_resolved_in_brand_scope(): void
    {
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        $context = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        $logical = (string) Str::ulid();
        foreach ([[$brand->id, 'Свой факт'], [$other->id, 'Чужой факт']] as [$brandId, $text]) {
            $release = (string) Str::ulid();
            $group = (string) Str::ulid();
            DB::table('knowledge_releases')->insert(['id' => $release, 'organization_id' => $org->id, 'brand_id' => $brandId, 'version' => 1, 'status' => 'draft', 'created_by' => $owner->id]);
            DB::table('kn_fact_groups')->insert(['id' => $group, 'release_id' => $release, 'key' => 'facts', 'position' => 0, 'data' => '{}']);
            DB::table('kn_facts')->insert(['id' => (string) Str::ulid(), 'logical_id' => $logical, 'group_id' => $group, 'text' => $text, 'position' => 0, 'source' => 'manual', 'status' => 'active']);
        }
        app(TenantContextRunner::class)->run($context, function () use ($context, $logical): void {
            app(AuditWriter::class)->record($context, new AuditEntry('knowledge.fact.updated', 'knowledge_fact', $logical));
            app(AuditWriter::class)->record($context, new AuditEntry('vision.profile.updated', 'brand', $context->brandId));
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $page = $this->get('/journal')->assertOk();
        $rows = collect($page->inertiaProps('rows.data'));
        self::assertSame('Свой факт', $rows->firstWhere('subject_id', $logical)['subject_name']);
        self::assertSame('/seller/knowledge', $rows->firstWhere('subject_id', $logical)['subject_url']);
        self::assertSame('Изменение профиля', $page->inertiaProps('actionLabels')['vision.profile.updated']);
        $csv = $this->get('/journal/export')->assertOk()->streamedContent();
        self::assertStringContainsString($brand->name, $csv);
        self::assertStringContainsString('Свой факт', $csv);
        self::assertStringNotContainsString('Чужой факт', $csv);
    }
}
