<?php

namespace Tests\Feature\Shell\Integrations;

use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;

final class IntegrationScreensE110GTest extends TenancyTestCase
{
    public function test_api_documentation_is_available_to_brand_admin_but_not_viewer(): void
    {
        Http::preventStrayRequests();
        [$owner, $organization, $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/integrations/api/docs')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Shell/Integrations/ApiDocumentation'));
        [$viewer] = $this->member($organization, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/integrations/api/docs')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_company_notification_matrix_is_saved_only_for_current_brand(): void
    {
        Http::preventStrayRequests();
        [$owner, , $brand, $other] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->put('/integrations/notifications', ['telegram_chat' => null, 'report_emails' => [],
            'matrix' => ['handoff' => ['cabinet' => true, 'email' => false, 'telegram' => false]]])->assertRedirect();
        $this->get('/integrations/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('matrix.handoff.cabinet', true)->where('matrix.handoff.email', false)
            ->where('matrix.report_published.telegram', false));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/integrations/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page->where('matrix.handoff.cabinet', false));
        $this->post('/brand/switch', ['brand_id' => $brand->id])->assertRedirect();
        $this->get('/integrations/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page->where('matrix.handoff.cabinet', true));
        Http::assertNothingSent();
    }

    public function test_matrix_rejects_unknown_event_and_invalid_toggle_and_viewer_cannot_save(): void
    {
        Http::preventStrayRequests();
        [$owner, $organization, $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $base = ['telegram_chat' => null, 'report_emails' => []];
        $this->put('/integrations/notifications', [...$base, 'matrix' => ['invented' => ['email' => true]]])->assertSessionHasErrors('matrix');
        $this->put('/integrations/notifications', [...$base, 'matrix' => ['handoff' => ['email' => 'perhaps']]])->assertSessionHasErrors('matrix.handoff.email');
        [$viewer] = $this->member($organization, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->put('/integrations/notifications', $base)->assertForbidden();
        Http::assertNothingSent();
    }
}
