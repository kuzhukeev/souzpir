<?php

namespace Tests\Feature\Seller\Customers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class CustomerConsentE603AATest extends TenancyTestCase
{
    public function test_manager_sets_and_revokes_consent_with_required_comment(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $contact = (string) Str::ulid();
        $conversation = (string) Str::ulid();
        DB::table('contacts')->insert(['id' => $contact, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'name' => 'Клиент А', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conversations')->insert(['id' => $conversation, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'contact_id' => $contact,
            'state' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);

        $this->get('/seller/customers/'.$contact)->assertOk()->assertInertia(fn ($page) => $page->where('consent.status', 'none')->where('consent.unsubscribed', 0)->where('consent.canEdit', true));

        $this->post('/seller/customers/'.$contact.'/consent', ['event' => 'granted'])->assertSessionHasErrors('basis');
        $this->post('/seller/customers/'.$contact.'/consent', ['event' => 'granted', 'basis' => 'Клиент подтвердил по телефону'])->assertRedirect();
        $this->assertDatabaseHas('seller_followup_consents', ['conversation_id' => $conversation, 'event' => 'granted', 'source' => 'manager', 'actor_id' => $owner->id]);
        $this->get('/seller/customers/'.$contact)->assertOk()->assertInertia(fn ($page) => $page->where('consent.status', 'granted')->where('consent.unsubscribed', 0));

        $this->post('/seller/customers/'.$contact.'/consent', ['event' => 'revoked', 'basis' => 'Клиент попросил не писать'])->assertRedirect();
        $this->get('/seller/customers/'.$contact)->assertOk()->assertInertia(fn ($page) => $page->where('consent.status', 'revoked')->where('consent.unsubscribed', 1));

        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])
            ->post('/seller/customers/'.$contact.'/consent', ['event' => 'granted', 'basis' => 'test'])->assertForbidden();
    }
}
