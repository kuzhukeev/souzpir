<?php

namespace Tests\Feature\Seller\Channels\Widget;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\SellerRuntime\Turns\ProtectedFollowupFixtures;
use Tests\Feature\Tenancy\TenancyTestCase;

final class WidgetConsentE603AATest extends TenancyTestCase
{
    use ProtectedFollowupFixtures;

    public function test_visitor_yes_after_dialog_question_is_recorded_as_dialog_consent(): void
    {
        Http::fake();
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->seedProtected(new TenantContext($org->id, $brand->id, $owner->id, $membership->id));
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/channels/widget')->assertOk();
        $key = DB::table('widget_settings')->where('brand_id', $brand->id)->value('public_key');
        $this->post('/seller/channels/widget', ['enabled' => true, 'origins' => ['https://example.test'],
            'appearance' => ['title' => 'Чат', 'color' => '#225544', 'position' => 'right'],
            'behavior' => ['invitation' => 'Здравствуйте!', 'collect_contact' => true, 'consent_url' => 'https://example.test/privacy']])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->withHeader('Origin', 'https://example.test');
        $token = $this->postJson('/widget/session?key='.$key)->assertCreated()->json('token');
        $url = '/widget/message?key='.$key;
        $this->postJson($url, ['token' => $token, 'text' => 'Здравствуйте', 'idempotency_key' => 'request-1'])->assertCreated();
        $conversationId = DB::table('widget_sessions')->where('token_hash', hash('sha256', $token))->value('conversation_id');
        self::assertNotNull($conversationId);

        DB::table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'conversation_id' => $conversationId, 'role' => 'agent', 'text' => 'Можно присылать вам напоминания и рассылку по акциям?',
            'meta' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        $this->postJson($url, ['token' => $token, 'text' => 'Да', 'idempotency_key' => 'request-2'])->assertCreated();
        $this->assertDatabaseHas('seller_followup_consents', ['conversation_id' => $conversationId, 'event' => 'granted', 'source' => 'dialog']);

        // consent_url (данные для виджета) не считается согласием на рассылку.
        $this->postJson('/widget/contact?key='.$key, ['session_token' => $token, 'consent' => true, 'name' => 'Гость', 'email' => 'guest@example.test'])->assertCreated();
        self::assertSame(1, DB::table('seller_followup_consents')->where('conversation_id', $conversationId)->count());
    }
}
