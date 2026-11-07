<?php

namespace Tests\Feature\Seller\Dialogs;

use App\Seller\Conversations\CanonicalJson;
use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ReadySuggestionE504PTest extends TenancyTestCase
{
    public function test_only_valid_suggest_mode_result_is_visible_to_authorized_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $claim = app(TenantContextRunner::class)->run($tenant, function () use ($tenant) {
            $conversation = Conversation::create(['state' => [], 'mode' => 'suggest']);
            $message = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Question', 'meta' => []]);

            return app(ConversationRepository::class)->claimTurn($tenant, new TurnCommand($conversation->id, $message->id, $message->text, null, 'test-'.Str::random(24), 0));
        });
        $outcome = ['status' => 'suggested', 'turnId' => $claim->turnId, 'conversationId' => $claim->conversationId, 'version' => 1, 'messages' => [['text' => 'Protected suggestion']]];
        DB::table('conversations')->where('id', $claim->conversationId)->update(['version' => 1]);
        DB::table('seller_turns')->where('id', $claim->turnId)->update(['status' => 'suggested', 'outcome' => json_encode($outcome), 'outcome_hash' => CanonicalJson::hash($outcome)]);
        $conversation = DB::table('conversations')->where('id', $claim->conversationId)->first();
        $turn = DB::table('seller_turns')->where('id', $claim->turnId)->first();
        $this->assertSame('suggest', $conversation->mode);
        $this->assertNotNull($turn->cutoff_sequence);
        $this->assertSame((int) $turn->base_history_revision + 1, (int) $conversation->history_revision);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/dialogs/'.$claim->conversationId)
            ->assertOk()->assertInertia(fn ($page) => $page->where('readySuggestion', 'Protected suggestion'));
        DB::table('seller_turns')->where('id', $claim->turnId)->update(['outcome_hash' => str_repeat('0', 64)]);
        $this->get('/seller/dialogs/'.$claim->conversationId)->assertOk()->assertInertia(fn ($page) => $page->where('readySuggestion', null));
        DB::table('seller_turns')->where('id', $claim->turnId)->update(['outcome_hash' => CanonicalJson::hash($outcome)]);
        DB::table('conversations')->where('id', $claim->conversationId)->update(['summary' => 'Updated context']);
        $this->get('/seller/dialogs/'.$claim->conversationId)->assertOk()->assertInertia(fn ($page) => $page->where('readySuggestion', null));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/dialogs/'.$claim->conversationId)->assertOk()->assertInertia(fn ($page) => $page->where('readySuggestion', null));
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/seller/dialogs/'.$claim->conversationId)->assertNotFound();
    }
}
