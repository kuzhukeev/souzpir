<?php

namespace Tests\Feature\Vision\Sources;

use App\Tenancy\TenantContext;
use App\Vision\Competitors\SnapshotView;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ConfirmedProfileE820HTest extends TenancyTestCase
{
    public function test_only_confirmed_same_brand_and_snapshot_profile_is_shown(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        [$set, $measurement, $answer, $confirmation, $factId, $decision] = array_map(fn () => (string) Str::ulid(), range(1, 6));
        DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'version' => 1, 'questions' => json_encode([['id' => 'q1', 'text' => 'Вопрос']]), 'size' => 1, 'checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('measurements')->insert(['id' => $measurement, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'question_set_id' => $set, 'status' => 'completed', 'configuration' => '{}', 'profile_snapshot' => '{}', 'profile_checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('measurement_answers')->insert(['id' => $answer, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'measurement_id' => $measurement, 'question_n' => 1, 'provider' => 'fake', 'requested_model' => 'fake', 'run_no' => 1, 'status' => 'ok', 'answer_text' => 'Ответ', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('snapshot_confirmations')->insert(['id' => $confirmation, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'measurement_id' => $measurement, 'confirmed_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        $fact = ['presence_candidates' => ['example.test']];
        DB::table('answer_facts')->insert(['id' => $factId, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'snapshot_confirmation_id' => $confirmation, 'measurement_answer_id' => $answer, 'answer_text' => 'Ответ', 'fact' => json_encode($fact), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('vision_clarification_decisions')->insert(['id' => $decision, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'snapshot_confirmation_id' => $confirmation, 'answer_fact_id' => $factId, 'item_key' => hash('sha256', 'presence:'.$factId.':example.test'), 'kind' => 'presence', 'decision' => 'yes', 'profile_url' => 'https://example.test/brand', 'decided_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        $snapshot = ['measurement_id' => $measurement, 'metrics' => ['sources' => [['domain' => 'example.test', 'answers' => 1]], 'slots' => [['id' => $answer, 'valid' => true, 'fact' => $fact, 'sources' => [], 'mentions' => []]]]];
        app()->instance(SnapshotView::class, new class($snapshot) extends SnapshotView
        {
            public function __construct(private array $snapshot) {}

            public function current(TenantContext $tenant): ?array
            {
                return $this->snapshot;
            }
        });
        $this->get('/vision/sources')->assertRedirect();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/vision/sources')->assertOk()->assertInertia(fn ($page) => $page->where('sources.0.profile_url', 'https://example.test/brand'));
        DB::table('answer_facts')->where('id', $factId)->update(['fact' => json_encode(['presence_candidates' => ['example.test'], 'changed' => true])]);
        $this->get('/vision/sources')->assertOk()->assertInertia(fn ($page) => $page->where('sources.0.profile_url', null));
        DB::table('answer_facts')->where('id', $factId)->update(['fact' => json_encode($fact)]);
        DB::table('vision_clarification_decisions')->where('id', $decision)->update(['profile_url' => 'https://other.test/brand']);
        $this->get('/vision/sources')->assertOk()->assertInertia(fn ($page) => $page->where('sources.0.profile_url', null));
        DB::table('vision_clarification_decisions')->where('id', $decision)->update(['profile_url' => 'https://example.test/brand', 'decision' => 'no']);
        $this->get('/vision/sources')->assertOk()->assertInertia(fn ($page) => $page->where('sources.0.profile_url', null));
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/vision/sources')->assertOk()->assertInertia(fn ($page) => $page->where('sources.0.profile_url', null));
    }
}
