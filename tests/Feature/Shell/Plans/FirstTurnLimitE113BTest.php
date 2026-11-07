<?php

namespace Tests\Feature\Shell\Plans;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Shell\Auth\AuthSessionRegistry;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FirstTurnLimitE113BTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        Queue::fake();
        config(['runtime.ai_driver' => 'fake']);
    }

    public function test_exhausted_monthly_dialog_limit_blocks_the_shared_first_turn_entry(): void
    {
        [$user, $brand, $input] = $this->fixture('0');
        $this->login($user, $brand);
        $this->postJson('/seller/turns/first', $input)->assertStatus(422)->assertJsonPath('message', 'Первый ход временно недоступен: превышен лимит тарифа.');
        self::assertSame(0, DB::table('seller_turns')->count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_unconfigured_limit_does_not_block_the_entry(): void
    {
        [$user, $brand, $input] = $this->fixture(null);
        $this->login($user, $brand);
        $this->postJson('/seller/turns/first', $input)->assertStatus(503);
        self::assertSame(0, DB::table('seller_turns')->count());
    }

    private function fixture(?string $dialogsMonthLimit): array
    {
        $user = User::create(['name' => 'Synthetic', 'email' => Str::random(12).'@example.test', 'password' => 'synthetic-test-password']);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->refresh();
        $org = Organization::create(['name' => 'Synthetic', 'owner_user_id' => $user->id]);
        [$member, $brand] = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $user->id), fn () => [
            Membership::create(['user_id' => $user->id, 'role' => 'owner']), Brand::create(['name' => 'Synthetic brand', 'timezone' => 'Europe/Moscow']),
        ]);
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        if ($dialogsMonthLimit !== null) {
            $plan = (string) Str::ulid();
            DB::table('plans')->insert(['id' => $plan, 'code' => 'synthetic-'.$plan, 'name' => 'Synthetic', 'is_public' => false]);
            DB::table('organization_plan')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'plan_id' => $plan, 'starts_at' => now()->subDay()]);
            DB::table('plan_limits')->insert(['id' => (string) Str::ulid(), 'plan_id' => $plan, 'key' => 'seller.dialogs_month', 'value' => $dialogsMonthLimit, 'unit' => 'шт.']);
        }
        $tenant = new TenantContext($org->id, $brand->id, $user->id, $member->id);
        [$conversation, $message] = app(TenantContextRunner::class)->run($tenant, function (): array {
            $conversation = Conversation::create(['state' => [], 'mode' => 'bot']);
            $message = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Synthetic first input', 'meta' => []]);

            return [$conversation, $message];
        });

        return [$user, $brand, ['conversation_id' => $conversation->id, 'message_id' => $message->id, 'text' => $message->text, 'idempotency_key' => 'first-turn-limit-test']];
    }

    private function login(User $user, Brand $brand): void
    {
        $this->actingAs($user);
        $this->startSession();
        session()->invalidate();
        $this->withSession(['auth.version' => $user->auth_version, 'brand_id' => $brand->id]);
        $request = Request::create('/', 'GET');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => $user);
        app(AuthSessionRegistry::class)->issue($request, $user, $user->auth_version);
        $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
    }
}
