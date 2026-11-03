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
}
