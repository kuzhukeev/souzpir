<?php

namespace Tests\Feature\Seller\Dialogs;

use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiGateway;
use App\Contracts\Ai\AiRequest;
use App\Contracts\Ai\AiResult;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\Tenancy\TenancyTestCase;

final class LiveDraftLV01Test extends TenancyTestCase
{
    private array $requests = [];

    private function setup_(string $reply, bool $fail = false, bool $enabled = true, string $mode = 'suggest'): array
    {
        config(['features.LV-01.defaults.enabled' => $enabled]);
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
        $data['facts'] = [['id' => 'f1', 'text' => 'Доставка по Москве бесплатная']];
        $knowledge = Mockery::mock(KnowledgeRepository::class);
        $knowledge->shouldReceive('published')->andReturn(new KnowledgeSnapshot($data, 'r1', str_repeat('a', 64)));
        $this->app->instance(KnowledgeRepository::class, $knowledge);
        $self = $this;
        $this->app->instance(AiGateway::class, new class($self, $reply, $fail) implements AiGateway
        {
            public function __construct(private $test, private string $reply, private bool $fail) {}

            public function complete(AiRequest $request, AiContext $context): AiResult
            {
                $this->test->capture($request, $context);

                return new AiResult($this->fail ? null : $this->reply, [], [], $request->model, 1, 0, 'call', $this->fail ? ['code' => 'provider'] : null);
            }
        });
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $conversation = app(TenantContextRunner::class)->run($tenant, function () use ($mode) {
            $c = Conversation::create(['state' => [], 'mode' => $mode]);
            Message::create(['conversation_id' => $c->id, 'role' => 'client', 'text' => 'Есть доставка?', 'meta' => []]);

            return $c;
        });

        return [$owner, $brand, $conversation];
    }

    public function capture(AiRequest $request, AiContext $context): void
    {
        $this->requests[] = [$request, $context];
    }

    public function test_client_message_produces_manager_visible_draft(): void
    {
        [$owner, $brand, $conversation] = $this->setup_('Да, по Москве доставка бесплатная.');
        $this->assertCount(1, $this->requests);
        $this->assertSame('seller', $this->requests[0][1]->family);
        $this->assertStringContainsString('Доставка по Москве бесплатная', $this->requests[0][0]->messages[0]['content']);
        $this->assertSame('user', $this->requests[0][0]->messages[1]['role']);
        $this->assertSame(0, DB::table('messages')->where('conversation_id', $conversation->id)->where('role', '!=', 'client')->count());
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/dialogs/'.$conversation->id)
            ->assertOk()->assertInertia(fn ($page) => $page->where('readySuggestion', 'Да, по Москве доставка бесплатная.'));
    }

    public function test_gateway_failure_leaves_no_draft_and_no_stuck_turn(): void
    {
        [$owner, $brand, $conversation] = $this->setup_('x', fail: true);
        $this->assertSame('failed', DB::table('seller_turns')->where('conversation_id', $conversation->id)->value('status'));
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/dialogs/'.$conversation->id)
            ->assertOk()->assertInertia(fn ($page) => $page->where('readySuggestion', null));
    }

    public function test_disabled_flag_does_nothing(): void
    {
        $this->setup_('x', enabled: false);
        $this->assertSame([], $this->requests);
        $this->assertSame(0, DB::table('seller_turns')->count());
    }

    public function test_non_suggest_mode_does_nothing(): void
    {
        $this->setup_('x', mode: 'manager');
        $this->assertSame([], $this->requests);
        $this->assertSame(0, DB::table('seller_turns')->count());
    }
}
