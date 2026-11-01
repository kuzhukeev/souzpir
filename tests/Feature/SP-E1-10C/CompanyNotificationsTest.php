<?php

namespace Tests\Feature\SP_E1_10C;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;

final class CompanyNotificationsTest extends TenancyTestCase
{
    public function test_owner_saves_brand_settings_and_fake_checks_without_sending(): void
    {
        Http::preventStrayRequests();
        [$owner, , $first, $second] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $first->id])
            ->put('/integrations/notifications', [
                'telegram_chat' => '-1001234567',
                'report_emails' => ['team@example.test', 'reports@example.test'],
            ])->assertRedirect('/integrations/notifications');
        $first->refresh();
        $this->assertStringNotContainsString('-1001234567', json_encode($first->settings));
        $this->get('/integrations/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Shell/Integrations/Notifications')
            ->where('telegram_configured', true)
            ->where('telegram_mask', '••••4567')
            ->where('report_emails.0', 'team@example.test'));
        $this->post('/integrations/notifications/test', ['channel' => 'telegram'])->assertRedirect('/integrations/notifications');
        $this->post('/integrations/notifications/test', ['channel' => 'email'])->assertRedirect('/integrations/notifications');
        $this->get('/integrations')->assertOk()->assertInertia(fn (Assert $page) => $page->where('connections.3.status', 'active'));
        $this->post('/brand/switch', ['brand_id' => $second->id])->assertRedirect();
        $this->get('/integrations/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('telegram_configured', false)->where('report_emails', []));
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    public function test_viewer_cannot_read_write_or_check_company_settings(): void
    {
        [, $organization, $brand] = $this->cabinet();
        [$viewer] = $this->member($organization, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/integrations/notifications')->assertForbidden();
        $this->put('/integrations/notifications', ['telegram_chat' => '123', 'report_emails' => []])->assertForbidden();
        $this->post('/integrations/notifications/test', ['channel' => 'email'])->assertForbidden();
    }

    public function test_invalid_chat_and_duplicate_email_are_rejected(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])
            ->put('/integrations/notifications', ['telegram_chat' => 'not-a-chat', 'report_emails' => ['ok@example.test']])
            ->assertSessionHasErrors('telegram_chat');
        $this->put('/integrations/notifications', ['telegram_chat' => '', 'report_emails' => ['a@example.test', 'A@example.test']])
            ->assertSessionHasErrors('report_emails.1');
        $this->post('/integrations/notifications/test', ['channel' => 'email'])->assertStatus(422);
    }
}
