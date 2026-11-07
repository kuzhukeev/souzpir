<?php

namespace Tests\Feature\Vision\Reports;

use App\Vision\Core\ConfirmedClarifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ClarificationErrorsE818GTest extends TenancyTestCase
{
    public function test_confirmed_error_can_be_reviewed_without_leaking_to_other_brand(): void
    {
        [$owner, $organization, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization->id,
                'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $set = (string) Str::ulid();
        $measurement = (string) Str::ulid();
        $answer = (string) Str::ulid();
        $confirmation = (string) Str::ulid();
        $factId = (string) Str::ulid();
        $fact = ['errors' => [['claim' => 'Неверная цена', 'source' => 'Ответ модели']]];
        DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $organization->id, 'brand_id' => $brand->id,
            'version' => 1, 'questions' => '[{"id":"q1","text":"Вопрос"}]', 'size' => 1, 'checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('measurements')->insert(['id' => $measurement, 'organization_id' => $organization->id, 'brand_id' => $brand->id,
            'question_set_id' => $set, 'status' => 'completed', 'configuration' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('measurement_answers')->insert(['id' => $answer, 'organization_id' => $organization->id, 'brand_id' => $brand->id,
            'measurement_id' => $measurement, 'question_n' => 1, 'provider' => 'fake', 'requested_model' => 'fake',
            'run_no' => 1, 'status' => 'ok', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('snapshot_confirmations')->insert(['id' => $confirmation, 'organization_id' => $organization->id,
            'brand_id' => $brand->id, 'measurement_id' => $measurement, 'confirmed_by' => $owner->id,
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('answer_facts')->insert(['id' => $factId, 'organization_id' => $organization->id, 'brand_id' => $brand->id,
            'snapshot_confirmation_id' => $confirmation, 'measurement_answer_id' => $answer, 'fact' => json_encode($fact),
            'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/vision/runs/'.$measurement.'/clarify/facts')->assertOk()
            ->assertInertia(fn ($page) => $page->where('items.0.value', 'Неверная цена')->where('pending', 1));
        $key = hash('sha256', 'facts:'.$factId.':Неверная цена');
        $this->post('/vision/runs/'.$measurement.'/clarify/facts', ['item_key' => $key, 'decision' => 'incorrect'])
            ->assertSessionHasErrors('correction');
        $this->post('/vision/runs/'.$measurement.'/clarify/facts', ['item_key' => $key, 'decision' => 'incorrect', 'correction' => 'Цена по запросу'])
            ->assertSessionHasNoErrors();
        $reviewed = app(ConfirmedClarifications::class)->read(['organization_id' => $organization->id, 'brand_id' => $brand->id], $measurement,
            [['id' => $answer, 'valid' => true, 'fact' => $fact, 'provider' => 'fake']]);
        self::assertSame('Неверная цена', $reviewed[0]['text']);
        self::assertSame('Ответ модели', $reviewed[0]['source']);

        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/vision/runs/'.$measurement.'/clarify/facts')->assertNotFound();
    }
}
