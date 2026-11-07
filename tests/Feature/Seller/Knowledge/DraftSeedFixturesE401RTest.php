<?php

namespace Tests\Feature\Seller\Knowledge;

use App\Ai\CanonicalJson;
use App\Ai\Drivers\FakeDriver;
use App\Ai\Drivers\Rejected;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DraftSeedFixturesE401RTest extends TenancyTestCase
{
    public function test_context_fixtures_are_reproducible_and_bound_to_all_evidence(): void
    {
        $personas = collect(Yaml::parseFile(base_path('database/seeders/Seller/Evaluation/personas.yaml')))->keyBy('id');
        $catalog = json_decode(file_get_contents(app_path('ProtectedOperations/DraftSimulation/fixtures/seeded-context-e401r.json')), true, 32, JSON_THROW_ON_ERROR);
        $dir = storage_path('framework/testing/e401r-'.Str::ulid());
        mkdir($dir, 0777, true);
        config(['features.E0-05.defaults.fixture_directory' => $dir]);
        foreach ($catalog as $example) {
            self::assertContains($example['criterion'], $personas[$example['persona']]['expect']);
            $input = $example;
            unset($input['expected']);
            $body = ['model' => 'fake-draft-judge', 'draft' => 'fixture', 'checksum' => str_repeat('a', 64), 'input' => $input];
            $events = collect($example['events']);
            if ($example['criterion'] === 'max_one_nudge') {
                self::assertSame($example['expected'], $events->where('type', 'nudge')->count() <= 1);
            } elseif ($example['criterion'] === 'paused_or_stale_outcome') {
                self::assertSame($example['expected'], $events->contains('type', 'outcome') ? true : null);
            } elseif ($example['criterion'] === 'price_sent') {
                self::assertSame($example['expected'], $events->contains(fn ($event) => $event['type'] === 'price_sent' && $event['artifact'] === $example['facts']['price_source']) ? true : null);
            } elseif ($example['criterion'] === 'escalate_big_order') {
                self::assertSame($example['expected'], $events->contains('type', 'handoff') ? true : null);
            } elseif ($example['criterion'] === 'calc_delivery_or_promise') {
                self::assertSame($example['expected'], $events->contains(fn ($event) => $event['type'] === 'delivery_quote_requested' && $event['destination'] === $example['facts']['destination']));
            } elseif ($example['criterion'] === 'no_pct_above_cap') {
                preg_match('/(\d+)%/u', $example['answer'], $matches);
                self::assertSame($example['expected'], (int) $matches[1] <= $example['facts']['discount_cap_percent']);
            }
            if ($example['expected'] !== null) {
                $result = ['checks' => [$example['criterion'] => $example['expected']]];
                file_put_contents($dir.'/'.CanonicalJson::hash($body).'.json', json_encode(['result' => ['content' => json_encode($result, JSON_THROW_ON_ERROR)]], JSON_THROW_ON_ERROR));
                self::assertSame($result, json_decode(app(FakeDriver::class)->complete($body)['content'], true, 32, JSON_THROW_ON_ERROR));
            }
        }
        $changedAnswer = $body;
        $changedAnswer['input']['answer'] .= ' изменено';
        try {
            app(FakeDriver::class)->complete($changedAnswer);
            self::fail('Изменённый ответ не должен получать прежнюю оценку.');
        } catch (Rejected) {
            self::assertTrue(true);
        }
        $body['input']['history'][] = ['role' => 'client', 'text' => 'Изменённый контекст'];
        try {
            app(FakeDriver::class)->complete($body);
            self::fail('Изменённый контекст не должен получать прежнюю оценку.');
        } catch (Rejected) {
            self::assertTrue(true);
        }
        $unverified = collect($catalog)->first(fn ($example) => $example['expected'] === null);
        unset($unverified['expected']);
        $body['input'] = $unverified;
        $this->expectException(Rejected::class);
        app(FakeDriver::class)->complete($body);
    }
}
