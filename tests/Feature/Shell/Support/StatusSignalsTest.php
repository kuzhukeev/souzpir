<?php

namespace Tests\Feature\Shell\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

final class StatusSignalsTest extends SupportTestCase
{
    public function test_saved_channel_measurement_and_ai_failures_are_scoped_to_brand(): void
    {
        [$owner, $organization, $brand, $other] = $this->cabinet();
        $channel = (string) Str::ulid();
        DB::table('channel_accounts')->insert(['id' => $channel, 'organization_id' => $organization->id, 'brand_id' => $brand->id,
            'type' => 'widget', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('channel_inbound_events')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'brand_id' => $brand->id,
            'channel_account_id' => $channel, 'external_id' => 'status-fixture', 'payload' => '{}', 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $set = (string) Str::ulid();
        DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $organization->id, 'brand_id' => $brand->id,
            'version' => 1, 'questions' => '["Вопрос"]', 'size' => 1, 'checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('measurements')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'brand_id' => $brand->id,
            'question_set_id' => $set, 'status' => 'failed', 'configuration' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $budget = (string) Str::ulid();
        DB::table('ai_budgets')->insert(['id' => $budget, 'organization_id' => $organization->id, 'brand_id' => $brand->id,
            'family' => 'seller', 'period' => now('UTC')->startOfMonth()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ai_calls')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'brand_id' => $brand->id,
            'budget_id' => $budget, 'idempotency_key_hash' => hash('sha256', 'status'), 'request_hash' => hash('sha256', 'request'),
            'family' => 'seller', 'purpose' => 'status', 'provider' => 'gemini', 'requested_model' => 'test-model', 'model' => 'test-model',
            'object_type' => 'brand', 'object_id' => $brand->id, 'data_classes' => '[]', 'attempt' => 1, 'status' => 'failed',
            'created_at' => now(), 'updated_at' => now()]);

        $this->signIn($owner, $brand);
        $this->get('/help/status')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('status.components.2.status', 'operational')->where('status.components.4.status', 'degraded')
            ->where('status.providers.items.0.status', 'degraded')->where('status.incidents.available', true)->has('status.incidents.data', 2));
        DB::table('channel_inbound_events')->where('channel_account_id', $channel)->update(['processed_at' => null]);
        $this->get('/help/status')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('status.components.2.status', 'unknown')->where('status.components.2.message', 'Последнее входящее событие ожидает обработки.'));
        $this->signIn($owner, $other);
        $this->get('/help/status')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('status.components.2.status', 'unknown')->where('status.components.4.status', 'unknown')
            ->where('status.providers.items.0.status', 'unknown')->where('status.incidents.available', false));
    }
}
