<?php

namespace Tests\Feature\SellerRuntime\Turns;

use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class TurnHttpTest extends TenancyTestCase
{
    public function test_manager_turn_is_suppressed_without_ai_and_replayed(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        config(['runtime.ai_driver' => 'fake']);
        [$owner,$org,$brand,$other,$membership] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $ctx = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        [$dialog,$message] = app(TenantContextRunner::class)->run($ctx, function () {
            $dialog = Conversation::create(['state' => [], 'mode' => 'manager']);
            $message = Message::create(['conversation_id' => $dialog->id, 'role' => 'client', 'text' => 'Тестовый вопрос', 'meta' => []]);

            return [$dialog, $message];
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $input = ['conversation_id' => $dialog->id, 'message_id' => $message->id, 'text' => $message->text, 'idempotency_key' => 'test-key', 'expected_version' => 0];
        $this->postJson('/seller/turns/runtime', $input)->assertOk()->assertJsonPath('data.status', 'suppressed');
        $this->postJson('/seller/turns/runtime', $input)->assertOk()->assertJsonPath('data.status', 'suppressed');
        $this->assertSame(1, DB::table('seller_turns')->where('conversation_id', $dialog->id)->count());
        $this->assertSame(1, (int) DB::table('conversations')->where('id', $dialog->id)->value('version'));
        Http::assertNothingSent();
        $this->postJson('/seller/turns/runtime', [...$input, 'expected_version' => -1])->assertUnprocessable();
        $this->postJson('/seller/turns/runtime', [...$input, 'brand_id' => $other->id])->assertUnprocessable();
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->postJson('/seller/turns/runtime', $input)->assertStatus(503);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->postJson('/seller/turns/runtime', $input)->assertForbidden();
    }
}
