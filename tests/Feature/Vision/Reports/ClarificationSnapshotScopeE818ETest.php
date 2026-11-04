<?php

namespace Tests\Feature\Vision\Reports;

use App\Vision\Core\ConfirmedClarifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ClarificationSnapshotScopeE818ETest extends TenancyTestCase
{
    public function test_only_decisions_from_the_selected_brand_and_measurement_are_read(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $fact = ['value' => 'Адрес магазина'];
        $slots = [];
        $measurements = [];
        foreach ([$brand, $other] as $item) {
            $set = (string) Str::ulid();
            $measurement = (string) Str::ulid();
            $answer = (string) Str::ulid();
            $confirmation = (string) Str::ulid();
            $factId = (string) Str::ulid();
            DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $org->id, 'brand_id' => $item->id, 'version' => 1, 'questions' => '[{"id":"q1","text":"Вопрос"}]', 'size' => 1, 'checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('measurements')->insert(['id' => $measurement, 'organization_id' => $org->id, 'brand_id' => $item->id, 'question_set_id' => $set, 'status' => 'completed', 'configuration' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('measurement_answers')->insert(['id' => $answer, 'organization_id' => $org->id, 'brand_id' => $item->id, 'measurement_id' => $measurement, 'question_n' => 1, 'provider' => 'fake', 'requested_model' => 'fake', 'run_no' => 1, 'status' => 'ok', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('snapshot_confirmations')->insert(['id' => $confirmation, 'organization_id' => $org->id, 'brand_id' => $item->id, 'measurement_id' => $measurement, 'confirmed_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('answer_facts')->insert(['id' => $factId, 'organization_id' => $org->id, 'brand_id' => $item->id, 'snapshot_confirmation_id' => $confirmation, 'measurement_answer_id' => $answer, 'fact' => json_encode($fact), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('vision_clarification_decisions')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'snapshot_confirmation_id' => $confirmation, 'answer_fact_id' => $factId, 'item_key' => hash('sha256', 'facts:'.$factId.':'.$fact['value']), 'kind' => 'facts', 'decision' => 'correct', 'decided_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
            $slots[] = ['id' => $answer, 'fact' => $fact, 'provider' => 'fake'];
            $measurements[] = $measurement;
        }
        $service = app(ConfirmedClarifications::class);
        $tenant = ['organization_id' => $org->id, 'brand_id' => $brand->id];
        self::assertCount(1, $service->read($tenant, $measurements[0], $slots));
        self::assertSame([], $service->read($tenant, $measurements[1], $slots));
        self::assertSame([], $service->read($tenant, $measurements[0], []));
        $this->get('/vision/report')->assertRedirect();
    }
}
