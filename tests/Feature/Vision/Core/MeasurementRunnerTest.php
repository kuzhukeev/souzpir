<?php

namespace Tests\Feature\Vision\Core;

use App\Ai\CanonicalJson;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\Core\MeasurementRunner;
use App\Vision\Core\QuestionSets;
use App\Vision\Core\ReportPreparation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

final class MeasurementRunnerTest extends TestCase
{
    use DatabaseMigrations;

    private string $organization;

    private string $brand;

    private string $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = (string) Str::ulid();
        $this->brand = (string) Str::ulid();
        DB::table('organizations')->insert(['id' => $this->organization, 'name' => 'Test', 'status' => 'active', 'settings' => '{}']);
        DB::table('brands')->insert(['id' => $this->brand, 'organization_id' => $this->organization, 'name' => 'Test', 'status' => 'active', 'theme' => 'neutral', 'settings' => '{}']);
        $this->fixtures = storage_path('app/vision-fixtures-'.Str::random(12));
        mkdir($this->fixtures, 0700, true);
        config(['runtime.ai_driver' => 'fake', 'features.E0-05.defaults.fixture_directory' => $this->fixtures]);
        Http::preventStrayRequests();
        Http::fake();
    }

    public function test_real_gateway_with_fake_fixture_builds_grid_and_never_duplicates_a_call(): void
    {
        $this->within(function (): void {
            $set = app(QuestionSets::class)->create([
                ['id' => 'q1', 'text' => 'Какие марки выбрать?', 'intent' => 'choose'],
                ['id' => 'q2', 'text' => 'Кто поставляет товар?', 'intent' => 'discovery'],
            ]);
            $id = app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 2);
            self::assertSame(4, DB::table('measurement_answers')->where('measurement_id', $id)->count());
            $frozen = DB::table('measurements')->where('id', $id)->first();
            self::assertNull($frozen->profile_snapshot);
            self::assertNull($frozen->profile_checksum);
            try {
                DB::table('measurements')->where('id', $id)->update(['configuration' => '{}']);
                self::fail('The frozen measurement plan cannot be rewritten.');
            } catch (QueryException) {
                self::assertSame($frozen->configuration, DB::table('measurements')->where('id', $id)->value('configuration'));
            }
            $answer = DB::table('measurement_answers')->where('measurement_id', $id)->where('question_n', 1)->where('run_no', 1)->first();
            $this->fixture('Какие марки выбрать?', 'Проверочный ответ');
            self::assertSame('ok', app(MeasurementRunner::class)->measure($answer->id));
            self::assertSame('ok', app(MeasurementRunner::class)->measure($answer->id));
            $saved = DB::table('measurement_answers')->where('id', $answer->id)->first();
            self::assertSame('Проверочный ответ', $saved->answer_text);
            self::assertNull($saved->fact); // A raw answer is not a verified fact.
            self::assertSame(1, DB::table('ai_calls')->count());
            self::assertSame('running', DB::table('measurements')->where('id', $id)->value('status'));
            Http::assertNothingSent();
        });
    }

    public function test_other_brand_cannot_start_or_read_a_measurement_and_live_is_closed(): void
    {
        [$set, $answer] = $this->within(function (): array {
            $set = app(QuestionSets::class)->create([['id' => 'q1', 'text' => 'Вопрос', 'intent' => 'choose']]);
            $measurement = app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 1);

            return [$set, DB::table('measurement_answers')->where('measurement_id', $measurement)->value('id')];
        });
        $other = (string) Str::ulid();
        DB::table('brands')->insert(['id' => $other, 'organization_id' => $this->organization, 'name' => 'Other', 'status' => 'active', 'theme' => 'neutral', 'settings' => '{}']);
        app(TenantContextRunner::class)->run(new TenantContext($this->organization, $other, source: 'job'), function () use ($set, $answer): void {
            foreach ([fn () => app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 1), fn () => app(MeasurementRunner::class)->measure($answer)] as $action) {
                try {
                    $action();
                    self::fail('Cross-brand access accepted.');
                } catch (LogicException) {
                    self::assertTrue(true);
                }
            }
        });
        try {
            DB::table('measurement_answers')->where('id', $answer)->update(['brand_id' => $other]);
            self::fail('A measurement slot cannot be reassigned across tenants.');
        } catch (QueryException) {
            self::assertSame($this->brand, DB::table('measurement_answers')->where('id', $answer)->value('brand_id'));
        }
        $this->within(function () use ($answer): void {
            config(['runtime.ai_driver' => 'openrouter', 'features.E0-05.defaults.live_enabled' => true]);
            try {
                app(MeasurementRunner::class)->measure($answer);
                self::fail('Live execution accepted.');
            } catch (LogicException) {
                self::assertSame('pending', DB::table('measurement_answers')->where('id', $answer)->value('status'));
            }
        });
        self::assertSame(0, DB::table('ai_calls')->count());
        Http::assertNothingSent();
    }

    public function test_missing_fake_fixture_fails_once_without_retrying(): void
    {
        $this->within(function (): void {
            $set = app(QuestionSets::class)->create([['id' => 'q1', 'text' => 'Неизвестная фикстура', 'intent' => 'choose']]);
            $measurement = app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 1);
            $answer = DB::table('measurement_answers')->where('measurement_id', $measurement)->value('id');
            self::assertSame('failed', app(MeasurementRunner::class)->measure($answer));
            self::assertSame('failed', app(MeasurementRunner::class)->measure($answer));
            self::assertSame('partial', DB::table('measurements')->where('id', $measurement)->value('status'));
            self::assertSame(1, DB::table('ai_calls')->count());
            Http::assertNothingSent();
        });
    }

    public function test_uncertain_cost_is_not_retried_or_counted_as_success(): void
    {
        $this->within(function (): void {
            $set = app(QuestionSets::class)->create([['id' => 'q1', 'text' => 'Неясная стоимость', 'intent' => 'choose']]);
            $measurement = app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 1);
            $answer = DB::table('measurement_answers')->where('measurement_id', $measurement)->value('id');
            $this->fixture('Неясная стоимость', 'Ответ', null);
            self::assertSame('uncertain', app(MeasurementRunner::class)->measure($answer));
            self::assertSame('uncertain', app(MeasurementRunner::class)->measure($answer));
            self::assertSame(1, DB::table('ai_calls')->count());
            self::assertSame('uncertain', DB::table('ai_calls')->value('status'));
            self::assertSame('partial', DB::table('measurements')->where('id', $measurement)->value('status'));
            self::assertNull(DB::table('measurement_answers')->where('id', $answer)->value('answer_text'));
            Http::assertNothingSent();
        });
    }

    public function test_definite_empty_response_is_failed_not_uncertain(): void
    {
        $this->within(function (): void {
            $set = app(QuestionSets::class)->create([['id' => 'q1', 'text' => 'Пустой ответ', 'intent' => 'choose']]);
            $measurement = app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 1);
            $answer = DB::table('measurement_answers')->where('measurement_id', $measurement)->value('id');
            $this->fixture('Пустой ответ', '');
            self::assertSame('failed', app(MeasurementRunner::class)->measure($answer));
            self::assertSame('empty_response', DB::table('measurement_answers')->where('id', $answer)->value('error_code'));
            self::assertSame('completed', DB::table('ai_calls')->value('status'));
            self::assertSame('partial', DB::table('measurements')->where('id', $measurement)->value('status'));
        });
    }

    public function test_model_substitution_is_recorded_as_protocol_invalid(): void
    {
        $this->within(function (): void {
            $set = app(QuestionSets::class)->create([['id' => 'q1', 'text' => 'Вопрос о модели', 'intent' => 'choose']]);
            $measurement = app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 1);
            $answer = DB::table('measurement_answers')->where('measurement_id', $measurement)->value('id');
            $this->fixture('Вопрос о модели', 'Ответ', 0, 'another/model');
            self::assertSame('ok', app(MeasurementRunner::class)->measure($answer));
            self::assertFalse((bool) DB::table('measurement_answers')->where('id', $answer)->value('protocol_valid'));
            $report = app(ReportPreparation::class)->snapshot($measurement);
            $payload = json_decode(DB::table('vision_reports')->where('id', $report)->value('payload'), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(1, $payload['metrics']['panel']['counts']['protocol_invalid']);
            self::assertSame(0, $payload['metrics']['panel']['valid']);
        });
    }

    public function test_snapshot_keeps_unconfirmed_answers_unknown_and_is_immutable(): void
    {
        $this->within(function (): void {
            $set = app(QuestionSets::class)->create([['id' => 'q1', 'text' => 'Вопрос', 'intent' => 'choose']]);
            $measurement = app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 1);
            try {
                app(ReportPreparation::class)->snapshot($measurement);
                self::fail('A running plan cannot be snapshotted.');
            } catch (LogicException) {
                self::assertSame(0, DB::table('vision_reports')->count());
            }
            $answer = DB::table('measurement_answers')->where('measurement_id', $measurement)->value('id');
            $this->fixture('Вопрос', 'Сырой ответ содержит Марку');
            self::assertSame('ok', app(MeasurementRunner::class)->measure($answer));
            // Until Evidence is integrated even untrusted fact-shaped data is ignored.
            DB::table('measurement_answers')->where('id', $answer)->update(['fact' => json_encode(['brand_mentioned' => true])]);
            $reportId = app(ReportPreparation::class)->snapshot($measurement);
            $row = DB::table('vision_reports')->where('id', $reportId)->first();
            $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('not_available', $payload['evidence_status']);
            self::assertSame(1, $payload['metrics']['panel']['planned']);
            self::assertSame(0, $payload['metrics']['panel']['valid']);
            self::assertNull($payload['metrics']['panel']['mention_rate']);
            self::assertNull($payload['metrics']['slots'][0]['fact']);
            self::assertStringNotContainsString('Сырой ответ', $row->payload);
            self::assertSame(CanonicalJson::hash($payload), $row->checksum);
            try {
                DB::table('vision_reports')->where('id', $reportId)->update(['payload' => '{}']);
                self::fail('Snapshots must be immutable.');
            } catch (QueryException) {
                self::assertSame($row->checksum, DB::table('vision_reports')->where('id', $reportId)->value('checksum'));
            }
            Http::assertNothingSent();
        });
    }

    public function test_fake_measurement_snapshots_keep_historical_question_counts_and_unknown_evidence(): void
    {
        $this->within(function (): void {
            $this->fixture('Одинаковый вопрос для фикстуры', 'Сырой ответ');
            $reports = [];
            foreach ([12, 20, 30] as $size) {
                $questions = array_map(fn (int $i): array => [
                    'id' => 'q'.$i, 'text' => 'Одинаковый вопрос для фикстуры', 'intent' => 'choose',
                ], range(1, $size));
                $set = app(QuestionSets::class)->create($questions);
                $measurement = app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 1);
                $answers = DB::table('measurement_answers')->where('measurement_id', $measurement)->orderBy('question_n')->pluck('id');
                self::assertCount($size, $answers);
                foreach ($answers as $answerId) {
                    self::assertSame('ok', app(MeasurementRunner::class)->measure($answerId));
                }
                $report = app(ReportPreparation::class)->snapshot($measurement);
                $payload = json_decode(DB::table('vision_reports')->where('id', $report)->value('payload'), true, flags: JSON_THROW_ON_ERROR);
                self::assertSame($size, $payload['metrics']['panel']['planned']);
                self::assertSame(0, $payload['metrics']['panel']['valid']);
                self::assertSame($size, $payload['metrics']['panel']['counts']['parse_failed']);
                self::assertNull($payload['metrics']['panel']['mention_rate']);
                $reports[] = $report;
            }
            self::assertSame(62, DB::table('ai_calls')->count());
            self::assertSame(3, DB::table('vision_reports')->whereIn('id', $reports)->count());
            Http::assertNothingSent();
        });
    }

    public function test_two_independent_workers_do_not_duplicate_a_measurement_call(): void
    {
        [$answer, $barrier] = $this->within(function (): array {
            $set = app(QuestionSets::class)->create([['id' => 'q1', 'text' => 'Параллельный вопрос', 'intent' => 'choose']]);
            $measurement = app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 1);
            $this->fixture('Параллельный вопрос', 'Ответ');

            return [DB::table('measurement_answers')->where('measurement_id', $measurement)->value('id'),
                storage_path('app/vision-barrier-'.Str::random(12))];
        });
        $processes = [];
        try {
            foreach ([1, 2] as $worker) {
                $command = [PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/measurement-worker.php',
                    $this->organization, $this->brand, $answer, $this->fixtures, $barrier, (string) $worker,
                    base_path(), DB::connection()->getDatabaseName()];
                $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                self::assertIsResource($process);
                fclose($pipes[0]);
                $processes[] = [$process, $pipes];
            }
            $deadline = microtime(true) + 45;
            while (! is_file($barrier.'.1') || ! is_file($barrier.'.2')) {
                if (microtime(true) > $deadline) {
                    self::fail('Measurement worker barrier timed out.');
                }
                usleep(10000);
            }
            file_put_contents($barrier, 'go');
            $statuses = [];
            foreach ($processes as [$process, $pipes]) {
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exit = proc_close($process);
                self::assertSame(0, $exit, $stderr);
                $statuses[] = $stdout;
            }
            $processes = [];
            self::assertContains('ok', $statuses);
            foreach ($statuses as $status) {
                self::assertContains($status, ['ok', 'in_progress']);
            }
            self::assertSame(1, DB::table('ai_calls')->count());
            self::assertSame('ok', DB::table('measurement_answers')->where('id', $answer)->value('status'));
        } finally {
            foreach ($processes as [$process, $pipes]) {
                proc_terminate($process);
                foreach ([1, 2] as $i) {
                    if (is_resource($pipes[$i])) {
                        fclose($pipes[$i]);
                    }
                }
                proc_close($process);
            }
        }
    }

    private function within(\Closure $action): mixed
    {
        return app(TenantContextRunner::class)->run(new TenantContext($this->organization, $this->brand, source: 'job'), $action);
    }

    private function fixture(string $question, string $answer, ?int $cost = 0, ?string $returnedModel = null): void
    {
        $body = ['messages' => [['role' => 'user', 'content' => $question]], 'model' => 'google/gemini-2.5-flash', 'temperature' => 0.7, 'max_tokens' => 1200];
        $result = ['content' => $answer, 'toolCalls' => [], 'usage' => ['promptTokens' => 2, 'completionTokens' => 3],
            'model' => $returnedModel ?? $body['model'], 'latencyMs' => 1, 'costMicros' => $cost];
        file_put_contents($this->fixtures.'/'.CanonicalJson::hash($body).'.json', json_encode(['result' => $result], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
