<?php

namespace Tests\Feature\Seller\Channels\Api;

use App\Models\Brand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ApiSourceE503ETest extends TenancyTestCase
{
    public function test_source_is_saved_shown_only_for_its_brand_and_checked_on_replay(): void
    {
        Http::preventStrayRequests();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id,
                'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $headers = ['Authorization' => 'Bearer '.$this->token($org->id, $brand), 'Idempotency-Key' => 'source-test'];
        $body = ['fields' => ['name' => 'Анна'], 'source' => 'site'];
        $first = $this->withHeaders($headers)->postJson('/api/v1/leads', $body)->assertCreated();
        $this->withHeaders($headers)->postJson('/api/v1/leads', $body)->assertOk()->assertJsonPath('contact_id', $first->json('contact_id'));
        $this->withHeaders($headers)->postJson('/api/v1/leads', [...$body, 'source' => 'crm'])->assertConflict();
        $this->withHeaders([...$headers, 'Idempotency-Key' => 'invalid'])->postJson('/api/v1/leads', [...$body, 'source' => 'other'])->assertUnprocessable();
        DB::table('conversations')->where('id', $first->json('conversation_id'))->update(['mode' => 'manager']);
        $turn = ['conversation_id' => $first->json('conversation_id'), 'text' => 'Вопрос', 'source' => 'crm'];
        $turnHeaders = [...$headers, 'Idempotency-Key' => 'turn-source'];
        $this->withHeaders($turnHeaders)->postJson('/api/v1/turn', $turn)->assertOk()->assertJsonPath('status', 'suppressed');
        $this->withHeaders($turnHeaders)->postJson('/api/v1/turn', $turn)->assertOk();
        $this->withHeaders($turnHeaders)->postJson('/api/v1/turn', [...$turn, 'source' => 'site'])->assertConflict();
        $headers = ['Authorization' => 'Bearer '.$this->token($org->id, $other), 'Idempotency-Key' => 'source-test'];
        $this->withHeaders($headers)->postJson('/api/v1/leads', [...$body, 'source' => 'crm'])->assertCreated();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/channels/api')->assertOk()
            ->assertInertia(fn ($page) => $page->where('logs', fn ($logs) => collect($logs)->where('status', 'success')->pluck('source')->sort()->values()->all() === ['crm', 'site'])
                ->where('logs', fn ($logs) => collect($logs)->where('status', 'error')->pluck('source')->contains('crm')));
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/seller/channels/api')->assertOk()
            ->assertInertia(fn ($page) => $page->where('logs', fn ($logs) => collect($logs)->where('status', 'success')->pluck('source')->all() === ['crm']));
        Http::assertNothingSent();
    }

    private function token(string $org, Brand $brand): string
    {
        $secret = Str::random(64);
        $id = DB::table('personal_access_tokens')->insertGetId(['tokenable_type' => Brand::class, 'tokenable_id' => $brand->id,
            'organization_id' => $org, 'brand_id' => $brand->id, 'name' => 'synthetic', 'token' => hash('sha256', $secret),
            'token_last_four' => substr($secret, -4), 'abilities' => json_encode(['leads', 'turn'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

        return $id.'|'.$secret;
    }
}
