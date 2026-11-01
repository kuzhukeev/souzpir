<?php

namespace Tests\Feature\Vision\Core;

use App\Ai\CanonicalJson;
use App\Ai\Drivers\Driver;
use App\Ai\Drivers\FakeDriver;
use App\Models\Membership;
use App\Models\User;
use App\Seller\Knowledge\DatabaseKnowledgeRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\Core\BoundedEvidenceSource;
use App\Vision\Core\EvidenceStore;
use App\Vision\Core\MeasurementRunner;
use App\Vision\Core\QuestionSets;
use App\Vision\Core\ReportPreparation;
use App\Vision\Core\ReportSeal;
use App\Vision\Core\VisionReportReader;
use App\Vision\Core\WorkloadLimits;
use App\Vision\Evidence\Contracts\KnowledgeGate;
use App\Vision\KnowledgeGrants\GrantRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Support\IsolatedDatabaseMigrations;
use Tests\TestCase;

final class MeasurementRunnerTest extends TestCase
{
    use IsolatedDatabaseMigrations;

    private string $organization;

    private string $brand;

    private string $fixtures;

    private TenantContext $ownerContext;

    private string $grantId;

    private string $revokeFailure = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = (string) Str::ulid();
        $this->brand = (string) Str::ulid();
        DB::table('organizations')->insert(['id' => $this->organization, 'name' => 'Test', 'status' => 'active', 'settings' => '{}']);
        DB::table('brands')->insert(['id' => $this->brand, 'organization_id' => $this->organization, 'name' => 'Test', 'status' => 'active', 'theme' => 'neutral', 'settings' => '{}']);
        $this->fixtures = storage_path('app/vision-fixtures-'.Str::random(12));
        mkdir($this->fixtures, 0700, true);
        config(['runtime.ai_driver' => 'fake', 'features.E0-05.defaults.fixture_directory' => $this->fixtures,
            'features.E8-01.defaults.parser' => ['model' => 'google/gemini-2.5-flash', 'temperature' => 0.0, 'max_tokens' => 1200]]);
        $this->prepareKnowledge();
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
            self::assertNotNull($frozen->profile_snapshot);
            self::assertNotNull($frozen->profile_checksum);
            $plan = json_decode($frozen->configuration, true, flags: JSON_THROW_ON_ERROR);
            self::assertArrayHasKey('parser', $plan);
            self::assertSame('google/gemini-2.5-flash', $plan['parser']['model']);
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
            try {
                DB::table('measurement_answers')->where('id', $answer->id)->update(['question_n' => 2]);
                self::fail('A completed answer cannot be moved to another question slot.');
            } catch (QueryException) {
                self::assertSame(1, DB::table('measurement_answers')->where('id', $answer->id)->value('question_n'));
            }
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

    public function test_planned_models_and_invalid_repeat_counts_do_not_create_a_run(): void
    {
        $this->within(function (): void {
            $set = app(QuestionSets::class)->create([['id' => 'q1', 'text' => 'Вопрос', 'intent' => 'choose']]);
            $attempts = [
                fn () => app(MeasurementRunner::class)->start($set, ['gigachat' => 'gigachat'], 1),
                fn () => app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 0),
                fn () => app(MeasurementRunner::class)->start($set, ['wrong-provider' => 'google/gemini-2.5-flash'], 1),
            ];
            foreach ($attempts as $attempt) {
                try {
                    $attempt();
                    self::fail('An invalid measurement plan was accepted.');
                } catch (\InvalidArgumentException) {
                    self::assertSame(0, DB::table('measurements')->count());
                    self::assertSame(0, DB::table('measurement_answers')->count());
                }
            }
            Http::assertNothingSent();
        });
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

    public function test_oversized_measurement_grid_is_rejected_before_any_insert(): void
    {
        $this->within(function (): void {
            $set = app(QuestionSets::class)->create([['id' => 'q1', 'text' => 'Вопрос', 'intent' => 'choose']]);
            config(['features.E8-01.defaults.workload.max_slots' => 5]);
            foreach ([6, PHP_INT_MAX] as $runs) {
                try {
                    app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], $runs);
                    self::fail('Oversized measurement grid accepted.');
                } catch (\InvalidArgumentException) {
                    self::assertSame(0, DB::table('measurements')->count());
                    self::assertSame(0, DB::table('measurement_answers')->count());
                }
            }
            $twoQuestions = app(QuestionSets::class)->create([
                ['id' => 'q1', 'text' => 'Вопрос 1', 'intent' => 'choose'],
                ['id' => 'q2', 'text' => 'Вопрос 2', 'intent' => 'choose'],
            ]);
            try {
                app(MeasurementRunner::class)->start($twoQuestions, ['gemini' => 'google/gemini-2.5-flash'], 3);
                self::fail('The product of questions and runs exceeded the limit.');
            } catch (\InvalidArgumentException) {
                self::assertSame(0, DB::table('measurements')->count());
                self::assertSame(0, DB::table('measurement_answers')->count());
            }
            config(['features.E8-01.defaults.workload.max_slots' => 0]);
            try {
                app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 1);
                self::fail('Missing safeguards accepted.');
            } catch (LogicException) {
                self::assertSame(0, DB::table('measurements')->count());
                self::assertSame(0, DB::table('measurement_answers')->count());
            }
            config(['features.E8-01.defaults.workload.max_slots' => 5]);
            $measurement = app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 5);
            self::assertSame(5, DB::table('measurement_answers')->where('measurement_id', $measurement)->count());
            Http::assertNothingSent();
        });
    }

    public function test_question_byte_limit_uses_the_same_postgresql_representation_at_create_and_start(): void
    {
        $this->within(function (): void {
            foreach (['Text', 'Фейерверк', "quoted \"name\" / path\nnext"] as $text) {
                $questions = [['id' => 'q1', 'text' => $text, 'intent' => 'choose']];
                $json = json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $bytes = (int) DB::selectOne('select octet_length(cast(cast(? as jsonb) as text)) as bytes', [$json])->bytes;
                config(['features.E8-01.defaults.workload.max_questions_bytes' => $bytes]);
                $set = app(QuestionSets::class)->create($questions);
                $measurement = app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 1);
                self::assertSame(1, DB::table('measurement_answers')->where('measurement_id', $measurement)->count());
                $before = DB::table('question_sets')->count();
                config(['features.E8-01.defaults.workload.max_questions_bytes' => $bytes - 1]);
                try {
                    app(QuestionSets::class)->create($questions);
                    self::fail('A question set over the byte limit was accepted.');
                } catch (\InvalidArgumentException) {
                    self::assertSame($before, DB::table('question_sets')->count());
                }
            }
            Http::assertNothingSent();
        });
    }

    public function test_snapshot_rejects_oversized_legacy_grid_before_loading_answers(): void
    {
        $this->within(function (): void {
            $questions = [['id' => 'q1', 'text' => 'Вопрос', 'intent' => 'choose']];
            $set = app(QuestionSets::class)->create($questions);
            $measurement = (string) Str::ulid();
            DB::table('measurements')->insert(['id' => $measurement, 'organization_id' => $this->organization, 'brand_id' => $this->brand,
                'question_set_id' => $set, 'status' => 'completed', 'configuration' => json_encode(['questions' => $questions,
                    'question_set_checksum' => CanonicalJson::hash($questions), 'providers' => ['gemini' => 'google/gemini-2.5-flash'], 'runs' => PHP_INT_MAX], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now()]);
            $answerQueries = [];
            DB::listen(function ($event) use (&$answerQueries): void {
                if (str_contains(strtolower($event->sql), 'measurement_answers')) {
                    $answerQueries[] = true;
                }
            });
            try {
                app(ReportPreparation::class)->snapshot($measurement);
                self::fail('An oversized legacy plan was accepted.');
            } catch (LogicException $exception) {
                self::assertSame('Vision report could not be safely prepared.', $exception->getMessage());
                self::assertNull($exception->getPrevious());
                self::assertSame([], $answerQueries);
                self::assertSame(0, DB::table('vision_reports')->count());
            }
            $oversized = (string) Str::ulid();
            DB::table('measurements')->insert(['id' => $oversized, 'organization_id' => $this->organization, 'brand_id' => $this->brand,
                'question_set_id' => $set, 'status' => 'completed', 'configuration' => json_encode(['questions' => $questions,
                    'question_set_checksum' => CanonicalJson::hash($questions), 'providers' => ['gemini' => 'google/gemini-2.5-flash'],
                    'runs' => 1, 'padding' => str_repeat('x', 1600000)], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            try {
                app(ReportPreparation::class)->snapshot($oversized);
                self::fail('An oversized legacy configuration was accepted.');
            } catch (LogicException $exception) {
                self::assertSame('Vision report could not be safely prepared.', $exception->getMessage());
                self::assertSame([], $answerQueries);
                self::assertSame(0, DB::table('vision_reports')->count());
            }
        });
    }

    public function test_lowered_limits_close_old_measurement_evidence_and_signed_report_before_full_reads(): void
    {
        $this->within(function (): void {
            $questions = [['id' => 'q1', 'text' => 'Вопрос', 'intent' => 'choose'], ['id' => 'q2', 'text' => 'Вопрос', 'intent' => 'choose']];
            $set = app(QuestionSets::class)->create($questions);
            $complete = app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 1);
            $pending = app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 1);
            $answers = DB::table('measurement_answers')->where('measurement_id', $complete)->orderBy('question_n')->pluck('id');
            $pendingAnswer = DB::table('measurement_answers')->where('measurement_id', $pending)->value('id');
            $this->fixture('Вопрос', 'Проверочный ответ');
            foreach ($answers as $answer) {
                self::assertSame('ok', app(MeasurementRunner::class)->measure($answer));
            }
            $report = app(ReportPreparation::class)->snapshot($complete);
            $storedBytes = (int) DB::table('question_sets')->where('id', $set)->selectRaw('octet_length(questions::text) as n')->value('n');
            $defaults = config('features.E8-01.defaults.workload');
            config(['features.E8-01.defaults.workload.max_slots' => 2, 'features.E8-01.defaults.workload.max_questions_bytes' => $storedBytes]);
            self::assertIsArray(app(VisionReportReader::class)->read($report));
            $calls = DB::table('ai_calls')->count();
            foreach ([['max_slots' => 1], ['max_questions_bytes' => 1]] as $override) {
                config(['features.E8-01.defaults.workload' => [...$defaults, ...$override]]);
                $fullReads = [];
                DB::listen(function ($event) use (&$fullReads): void {
                    $sql = strtolower($event->sql);
                    if (preg_match('/select\s+\*\s+from\s+"(?:measurements|measurement_answers|vision_reports|ai_calls)"/', $sql)) {
                        $fullReads[] = true;
                    }
                });
                try {
                    app(MeasurementRunner::class)->measure($pendingAnswer);
                    self::fail('An old over-limit measurement was allowed to run.');
                } catch (LogicException) {
                    self::assertSame($calls, DB::table('ai_calls')->count());
                }
                self::assertSame('unknown', app(EvidenceStore::class)->evaluate($answers[0]));
                try {
                    app(VisionReportReader::class)->read($report);
                    self::fail('An old over-limit signed report was returned.');
                } catch (LogicException $exception) {
                    self::assertSame('Vision report is unavailable.', $exception->getMessage());
                }
                self::assertSame([], $fullReads, 'Large plan, answer, call or payload was loaded before preflight.');
            }
            self::assertSame('pending', DB::table('measurement_answers')->where('id', $pendingAnswer)->value('status'));
            Http::assertNothingSent();
        });
    }

    public function test_snapshot_rejects_aggregate_answer_and_ai_result_bytes_before_materializing_rows(): void
    {
        foreach (['answer', 'call'] as $kind) {
            $this->within(function () use ($kind): void {
                $questions = array_map(fn ($n) => ['id' => 'q'.$n, 'text' => 'Вопрос', 'intent' => 'choose'], range(1, 3));
                $set = app(QuestionSets::class)->create($questions);
                $measurement = app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 1);
                $answers = DB::table('measurement_answers')->where('measurement_id', $measurement)->pluck('id');
                $this->fixture('Вопрос', 'Ответ');
                foreach ($answers as $answer) {
                    self::assertSame('ok', app(MeasurementRunner::class)->measure($answer));
                }
                $large = str_repeat('x', intdiv(WorkloadLimits::dataBytes(), 2) + 1);
                if ($kind === 'answer') {
                    DB::table('measurement_answers')->where('measurement_id', $measurement)->update(['answer_text' => $large]);
                } else {
                    $ids = DB::table('measurement_answers')->where('measurement_id', $measurement)->pluck('ai_call_id');
                    DB::table('ai_calls')->whereIn('id', $ids)->update(['result_encrypted' => $large]);
                }
                $fullReads = [];
                DB::listen(function ($event) use (&$fullReads): void {
                    if (preg_match('/select\s+\*\s+from\s+"(?:measurement_answers|ai_calls)"/', strtolower($event->sql))) {
                        $fullReads[] = true;
                    }
                });
                try {
                    app(ReportPreparation::class)->snapshot($measurement);
                    self::fail('Aggregate bytes over the limit were accepted.');
                } catch (LogicException $exception) {
                    self::assertSame('Vision report could not be safely prepared.', $exception->getMessage());
                    self::assertSame([], $fullReads);
                    self::assertSame(0, DB::table('vision_reports')->where('measurement_id', $measurement)->count());
                }
            });
        }
        Http::assertNothingSent();
    }

    #[Group('race')]
    public function test_snapshot_locks_sized_answers_until_its_fixed_set_is_read(): void
    {
        $this->within(function (): void {
            $set = app(QuestionSets::class)->create([['id' => 'q1', 'text' => 'Вопрос', 'intent' => 'choose']]);
            $measurement = app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 1);
            $answer = DB::table('measurement_answers')->where('measurement_id', $measurement)->value('id');
            $this->fixture('Вопрос', 'Ответ');
            self::assertSame('ok', app(MeasurementRunner::class)->measure($answer));
            $triggered = false;
            $waited = false;
            $process = null;
            DB::listen(function ($event) use (&$triggered, &$waited, &$process, $answer): void {
                $sql = strtolower($event->sql);
                if ($triggered || ! str_contains($sql, 'measurement_answers') || ! str_contains($sql, 'as data_bytes') || ! str_contains($sql, 'for update')) {
                    return;
                }
                $triggered = true;
                $process = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/snapshot-answer-worker.php',
                    $answer, base_path(), DB::connection()->getDatabaseName()], base_path(), ['APP_ENV' => 'testing'], null, 40);
                $process->start();
                for ($i = 0; $i < 200 && $process->isRunning(); $i++) {
                    DB::statement('SELECT pg_stat_clear_snapshot()');
                    if (DB::table('pg_stat_activity')->where('application_name', 'vision_snapshot_answer_test')->where('wait_event_type', 'Lock')->exists()) {
                        $waited = true;
                        break;
                    }
                    usleep(25000);
                }
            });
            try {
                $report = app(ReportPreparation::class)->snapshot($measurement);
                self::assertTrue($triggered);
                self::assertTrue($waited, 'The competing update must wait on the captured answer lock.');
                self::assertNotNull($process);
                $process->wait();
                self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                self::assertSame("READY\nCOMMITTED", trim(str_replace("\r\n", "\n", $process->getOutput())));
                self::assertDatabaseHas('vision_reports', ['id' => $report, 'measurement_id' => $measurement]);
                self::assertGreaterThan(WorkloadLimits::dataBytes(), (int) DB::table('measurement_answers')->where('id', $answer)->selectRaw('octet_length(answer_text) as n')->value('n'));
            } finally {
                if ($process?->isRunning()) {
                    $process->stop();
                }
            }
            Http::assertNothingSent();
        });
    }

    #[Group('race')]
    public function test_snapshot_rechecks_size_after_waiting_for_a_competing_answer_write(): void
    {
        $this->within(function (): void {
            $set = app(QuestionSets::class)->create([['id' => 'q1', 'text' => 'Вопрос', 'intent' => 'choose']]);
            $measurement = app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 1);
            $answer = DB::table('measurement_answers')->where('measurement_id', $measurement)->value('id');
            $this->fixture('Вопрос', 'Ответ');
            self::assertSame('ok', app(MeasurementRunner::class)->measure($answer));
            DB::beginTransaction();
            $process = null;
            try {
                DB::table('measurement_answers')->where('id', $answer)->lockForUpdate()->first(['id']);
                $process = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/snapshot-create-worker.php',
                    $this->organization, $this->brand, $measurement, base_path(), DB::connection()->getDatabaseName()], base_path(), ['APP_ENV' => 'testing'], null, 40);
                $process->start();
                $waited = false;
                for ($i = 0; $i < 200 && $process->isRunning(); $i++) {
                    DB::statement('SELECT pg_stat_clear_snapshot()');
                    if (DB::table('pg_stat_activity')->where('application_name', 'vision_snapshot_create_test')->where('wait_event_type', 'Lock')->exists()) {
                        $waited = true;
                        break;
                    }
                    usleep(25000);
                }
                self::assertTrue($waited, 'Snapshot must wait for the answer lock.');
                DB::table('measurement_answers')->where('id', $answer)->update(['answer_text' => str_repeat('x', WorkloadLimits::dataBytes() + 1)]);
                DB::commit();
                $process->wait();
                self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                self::assertSame("READY\nDENIED", trim(str_replace("\r\n", "\n", $process->getOutput())));
                self::assertSame(0, DB::table('vision_reports')->where('measurement_id', $measurement)->count());
            } finally {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                if ($process?->isRunning()) {
                    $process->stop();
                }
            }
            Http::assertNothingSent();
        });
    }

    public function test_bounded_evidence_source_rechecks_answer_size_in_the_actual_read(): void
    {
        $this->within(function (): void {
            $set = app(QuestionSets::class)->create([['id' => 'q1', 'text' => 'Вопрос', 'intent' => 'choose']]);
            $measurement = app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 1);
            $answer = DB::table('measurement_answers')->where('measurement_id', $measurement)->value('id');
            $this->fixture('Вопрос', 'Ответ');
            self::assertSame('ok', app(MeasurementRunner::class)->measure($answer));
            $source = app(BoundedEvidenceSource::class);
            self::assertNotNull($source->read(app(TenantContext::class), $answer));
            app(TenantContextRunner::class)->run(new TenantContext($this->organization, $this->brand, source: 'public'), function () use ($source, $answer): void {
                self::assertNull($source->read(app(TenantContext::class), $answer));
            });
            $changed = false;
            DB::listen(function ($event) use (&$changed, $answer): void {
                if (! $changed && str_contains(strtolower($event->sql), 'as question_count')) {
                    $changed = true;
                    DB::table('measurement_answers')->where('id', $answer)->update(['answer_text' => str_repeat('x', WorkloadLimits::dataBytes() + 1)]);
                }
            });
            self::assertNull($source->read(app(TenantContext::class), $answer));
            self::assertTrue($changed);
            Http::assertNothingSent();
        });
    }

    public function test_oversized_signed_report_and_answer_are_rejected_before_full_reads(): void
    {
        $this->within(function (): void {
            $set = app(QuestionSets::class)->create([['id' => 'q1', 'text' => 'Вопрос', 'intent' => 'choose']]);
            $measurement = app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 1);
            $answer = DB::table('measurement_answers')->where('measurement_id', $measurement)->value('id');
            $this->fixture('Вопрос', 'Ответ');
            self::assertSame('ok', app(MeasurementRunner::class)->measure($answer));
            $reportId = app(ReportPreparation::class)->snapshot($measurement);
            $report = DB::table('vision_reports')->where('id', $reportId)->first();
            $payload = json_decode($report->payload, true, 128, JSON_THROW_ON_ERROR);
            $payload['padding'] = '';
            $empty = json_encode($payload, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            $overhead = (int) DB::selectOne('select octet_length(cast(cast(? as jsonb) as text)) as bytes', [$empty])->bytes;
            $payload['padding'] = str_repeat('x', WorkloadLimits::dataBytes() - $overhead);
            $exactId = (string) Str::ulid();
            $checksum = CanonicalJson::hash($payload);
            DB::table('vision_reports')->insert(['id' => $exactId, 'organization_id' => $this->organization, 'brand_id' => $this->brand,
                'measurement_id' => $measurement, 'revision' => 98, 'payload' => json_encode($payload, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), 'checksum' => $checksum,
                'snapshot_mac' => ReportSeal::mac($this->organization, $this->brand, $exactId, $measurement, 98, $checksum), 'created_at' => now(), 'updated_at' => now()]);
            self::assertSame(strlen($payload['padding']), strlen(app(VisionReportReader::class)->read($exactId)['padding']));
            $payload['padding'] .= 'x';
            $hugeId = (string) Str::ulid();
            $checksum = CanonicalJson::hash($payload);
            DB::table('vision_reports')->insert(['id' => $hugeId, 'organization_id' => $this->organization, 'brand_id' => $this->brand,
                'measurement_id' => $measurement, 'revision' => 99, 'payload' => json_encode($payload, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), 'checksum' => $checksum,
                'snapshot_mac' => ReportSeal::mac($this->organization, $this->brand, $hugeId, $measurement, 99, $checksum), 'created_at' => now(), 'updated_at' => now()]);
            unset($payload);
            $fullReads = [];
            DB::listen(function ($event) use (&$fullReads): void {
                if (preg_match('/select\s+\*\s+from\s+"(?:vision_reports|measurement_answers|measurements|ai_calls)"/', strtolower($event->sql))) {
                    $fullReads[] = true;
                }
            });
            try {
                app(VisionReportReader::class)->read($hugeId);
                self::fail('An oversized signed report was returned.');
            } catch (LogicException $exception) {
                self::assertSame('Vision report is unavailable.', $exception->getMessage());
            }
            self::assertSame([], $fullReads);
            DB::table('measurement_answers')->where('id', $answer)->update(['answer_text' => str_repeat('x', WorkloadLimits::dataBytes() + 1)]);
            self::assertSame('unknown', app(EvidenceStore::class)->evaluate($answer));
            self::assertSame([], $fullReads);
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

    #[Group('race')]
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

    public function test_real_evidence_is_persisted_with_provenance_and_used_in_report(): void
    {
        $this->within(function (): void {
            $set = app(QuestionSets::class)->create([['id' => 'q1', 'text' => 'Какие марки выбрать?', 'intent' => 'choose']]);
            $measurement = app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 1);
            $answer = DB::table('measurement_answers')->where('measurement_id', $measurement)->value('id');
            $this->fixture('Какие марки выбрать?', 'Рекомендую Test.');
            self::assertSame('ok', app(MeasurementRunner::class)->measure($answer));
            $candidate = ['brand_mentioned' => true, 'brand_position' => 1, 'brand_recommended' => true,
                'mention_quote' => 'Рекомендую Test', 'companies' => [['name' => 'Test', 'position' => 1, 'role' => 'supplier', 'is_brand' => true]],
                'tone' => 'positive', 'claims' => [], 'suspected_errors' => [], 'confidence' => 0.9];
            $driver = new class($candidate) implements Driver
            {
                public int $calls = 0;

                public function __construct(private array $candidate) {}

                public function complete(array $body): array
                {
                    $this->calls++;

                    return ['content' => json_encode($this->candidate, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'toolCalls' => [],
                        'usage' => ['promptTokens' => 1, 'completionTokens' => 1], 'model' => $body['model'], 'latencyMs' => 1, 'costMicros' => 0];
                }
            };
            app()->instance(FakeDriver::class, $driver);
            self::assertSame('confirmed', app(EvidenceStore::class)->evaluate($answer));
            self::assertSame(1, $driver->calls);
            $row = DB::table('measurement_answers')->where('id', $answer)->first();
            self::assertNotNull($row->evidence_signature);
            self::assertNotNull($row->evidence_mac);
            $report = app(ReportPreparation::class)->snapshot($measurement);
            $payload = json_decode(DB::table('vision_reports')->where('id', $report)->value('payload'), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($payload, app(VisionReportReader::class)->read($report));
            $originalKey = config('app.key');
            config(['app.key' => '']);
            try {
                app(VisionReportReader::class)->read($report);
                self::fail('A report cannot be verified without a signing key.');
            } catch (LogicException $exception) {
                self::assertSame('Vision report is unavailable.', $exception->getMessage());
            } finally {
                config(['app.key' => $originalKey]);
            }
            self::assertSame('available', $payload['evidence_status']);
            self::assertSame(100.0, $payload['metrics']['panel']['mention_rate']);
            self::assertSame(100.0, $payload['metrics']['panel']['recommended_rate']);
            self::assertNull($payload['metrics']['slots'][0]['mentions'][0]['role']);
            $forged = (array) DB::table('vision_reports')->where('id', $report)->first();
            $forged['id'] = (string) Str::ulid();
            $forged['revision'] = 99;
            $forgedPayload = $payload;
            $forgedPayload['evidence_status'] = 'forged';
            $forged['payload'] = json_encode($forgedPayload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            $forged['checksum'] = CanonicalJson::hash($forgedPayload);
            DB::table('vision_reports')->insert($forged);
            try {
                app(VisionReportReader::class)->read($forged['id']);
                self::fail('Corrupt report was returned.');
            } catch (LogicException $exception) {
                self::assertSame('Vision report is unavailable.', $exception->getMessage());
                self::assertNull($exception->getPrevious());
            }
            $legacy = (array) DB::table('vision_reports')->where('id', $report)->first();
            $legacy['id'] = (string) Str::ulid();
            $legacy['revision'] = 100;
            $legacy['snapshot_mac'] = null;
            DB::table('vision_reports')->insert($legacy);
            try {
                app(VisionReportReader::class)->read($legacy['id']);
                self::fail('An unsigned legacy report was returned.');
            } catch (LogicException $exception) {
                self::assertSame('Vision report is unavailable.', $exception->getMessage());
            }
            $other = (string) Str::ulid();
            DB::table('brands')->insert(['id' => $other, 'organization_id' => $this->organization, 'name' => 'Other', 'status' => 'active', 'theme' => 'neutral', 'settings' => '{}']);
            app(TenantContextRunner::class)->run(new TenantContext($this->organization, $other, source: 'job'), function () use ($report): void {
                try {
                    app(VisionReportReader::class)->read($report);
                    self::fail('Cross-brand report was returned.');
                } catch (LogicException $exception) {
                    self::assertSame('Vision report is unavailable.', $exception->getMessage());
                }
            });
            DB::table('measurement_answers')->where('id', $answer)->update(['fact' => json_encode(['brand_mentioned' => false], JSON_THROW_ON_ERROR)]);
            $tamperedReport = app(ReportPreparation::class)->snapshot($measurement);
            $tampered = json_decode(DB::table('vision_reports')->where('id', $tamperedReport)->value('payload'), true, flags: JSON_THROW_ON_ERROR);
            self::assertNull($tampered['metrics']['slots'][0]['fact']);
            DB::table('measurement_answers')->where('id', $answer)->update(['fact' => $row->fact]);
            app(TenantContextRunner::class)->run($this->ownerContext, fn () => app(GrantRegistry::class)->revoke($this->ownerContext, $this->grantId));
            try {
                app(VisionReportReader::class)->read($report);
                self::fail('Report was returned after revocation.');
            } catch (LogicException $exception) {
                self::assertSame('Vision report is unavailable.', $exception->getMessage());
                self::assertNull($exception->getPrevious());
            }
            $revokedReport = app(ReportPreparation::class)->snapshot($measurement);
            $revoked = json_decode(DB::table('vision_reports')->where('id', $revokedReport)->value('payload'), true, flags: JSON_THROW_ON_ERROR);
            self::assertNull($revoked['metrics']['slots'][0]['fact']);
            self::assertSame(0, $revoked['metrics']['panel']['valid']);
            Http::assertNothingSent();
        });
    }

    public function test_missing_parser_or_revoked_grant_never_sends_to_gateway(): void
    {
        $this->within(function (): void {
            $set = app(QuestionSets::class)->create([['id' => 'q1', 'text' => 'Вопрос', 'intent' => 'choose']]);
            config(['features.E8-01.defaults.parser' => null]);
            try {
                app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 1);
                self::fail('Missing parser must fail closed.');
            } catch (LogicException) {
                self::assertSame(0, DB::table('measurements')->count());
            }
            config(['features.E8-01.defaults.parser' => ['model' => 'google/gemini-2.5-flash', 'temperature' => 0.0, 'max_tokens' => 1200]]);
            $measurement = app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 1);
            $answer = DB::table('measurement_answers')->where('measurement_id', $measurement)->value('id');
            app(TenantContextRunner::class)->run($this->ownerContext, fn () => app(GrantRegistry::class)->revoke($this->ownerContext, $this->grantId));
            try {
                app(MeasurementRunner::class)->measure($answer);
                self::fail('Revoked knowledge must stop the call.');
            } catch (LogicException) {
                self::assertSame(0, DB::table('ai_calls')->count());
                self::assertSame('pending', DB::table('measurement_answers')->where('id', $answer)->value('status'));
            }
            self::assertSame('unknown', app(EvidenceStore::class)->evaluate($answer));
            Http::assertNothingSent();
        });
    }

    #[Group('race')]
    public function test_revocation_in_another_process_before_report_gate_prevents_reading(): void
    {
        $this->within(function (): void {
            $set = app(QuestionSets::class)->create([['id' => 'q1', 'text' => 'Вопрос', 'intent' => 'choose']]);
            $measurement = app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 1);
            $answer = DB::table('measurement_answers')->where('measurement_id', $measurement)->value('id');
            $this->fixture('Вопрос', 'Ответ');
            self::assertSame('ok', app(MeasurementRunner::class)->measure($answer));
            $report = app(ReportPreparation::class)->snapshot($measurement);
            $delegate = app(KnowledgeGate::class);
            $revoke = fn () => $this->revokeInWorker();
            app()->instance(KnowledgeGate::class, new class($delegate, $revoke) implements KnowledgeGate
            {
                public function __construct(private KnowledgeGate $delegate, private \Closure $revoke) {}

                public function assertAllowed(TenantContext $tenant, string $measurementId, array $frozenProfile): void
                {
                    ($this->revoke)();
                    $this->delegate->assertAllowed($tenant, $measurementId, $frozenProfile);
                }
            });
            try {
                app(VisionReportReader::class)->read($report);
                self::fail('A report cannot be read after the grant is revoked.');
            } catch (LogicException $exception) {
                self::assertSame('', $this->revokeFailure, $this->revokeFailure);
                self::assertSame('Vision report is unavailable.', $exception->getMessage());
                self::assertNotNull(DB::table('vision_knowledge_grants')->where('id', $this->grantId)->value('revoked_at'));
            }
            Http::assertNothingSent();
        });
    }

    public function test_gate_and_database_failures_do_not_expose_profile_or_answer_in_exceptions(): void
    {
        $this->within(function (): void {
            $set = app(QuestionSets::class)->create([['id' => 'q1', 'text' => 'Вопрос', 'intent' => 'choose']]);
            $measurement = app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 1);
            $answer = DB::table('measurement_answers')->where('measurement_id', $measurement)->value('id');
            $gate = app(KnowledgeGate::class);
            app()->instance(KnowledgeGate::class, new class implements KnowledgeGate
            {
                public function assertAllowed(TenantContext $tenant, string $measurementId, array $frozenProfile): void
                {
                    throw new LogicException('synthetic-profile-secret');
                }
            });
            try {
                app(MeasurementRunner::class)->measure($answer);
                self::fail('The failed gate must stop the measurement.');
            } catch (LogicException $exception) {
                self::assertSame('Vision measurement could not be safely completed.', $exception->getMessage());
                self::assertNull($exception->getPrevious());
                $this->assertTraceDoesNotContain($exception, 'synthetic-profile-secret');
            }
            app()->instance(KnowledgeGate::class, $gate);
            $this->fixture('Вопрос', 'synthetic-answer-secret');
            DB::unprepared("CREATE FUNCTION vision_test_write_failure() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.status = 'ok' THEN RAISE EXCEPTION 'synthetic-answer-secret'; END IF; RETURN NEW; END \$\$");
            DB::statement('CREATE TRIGGER vision_test_write_failure BEFORE UPDATE ON measurement_answers FOR EACH ROW EXECUTE FUNCTION vision_test_write_failure()');
            try {
                app(MeasurementRunner::class)->measure($answer);
                self::fail('The simulated write must fail.');
            } catch (LogicException $exception) {
                self::assertSame('Vision measurement could not be safely completed.', $exception->getMessage());
                self::assertNull($exception->getPrevious());
                self::assertStringNotContainsString('synthetic-answer-secret', (string) $exception);
                $this->assertTraceDoesNotContain($exception, 'synthetic-answer-secret');
            } finally {
                DB::statement('DROP TRIGGER IF EXISTS vision_test_write_failure ON measurement_answers');
                DB::statement('DROP FUNCTION IF EXISTS vision_test_write_failure()');
            }
            Http::assertNothingSent();
        });
    }

    #[Group('race')]
    public function test_concurrent_revocation_before_evidence_persist_leaves_fact_unknown(): void
    {
        $this->within(function (): void {
            $set = app(QuestionSets::class)->create([['id' => 'q1', 'text' => 'Какие марки выбрать?', 'intent' => 'choose']]);
            $measurement = app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 1);
            $answer = DB::table('measurement_answers')->where('measurement_id', $measurement)->value('id');
            $this->fixture('Какие марки выбрать?', 'Рекомендую Test.');
            self::assertSame('ok', app(MeasurementRunner::class)->measure($answer));
            $candidate = ['brand_mentioned' => true, 'brand_position' => 1, 'brand_recommended' => true, 'mention_quote' => 'Рекомендую Test',
                'companies' => [['name' => 'Test', 'position' => 1, 'role' => 'supplier', 'is_brand' => true]],
                'tone' => 'positive', 'claims' => [], 'suspected_errors' => [], 'confidence' => 0.9];
            app()->instance(FakeDriver::class, new class($candidate) implements Driver
            {
                public function __construct(private array $candidate) {}

                public function complete(array $body): array
                {
                    return ['content' => json_encode($this->candidate, JSON_THROW_ON_ERROR), 'toolCalls' => [],
                        'usage' => ['promptTokens' => 1, 'completionTokens' => 1], 'model' => $body['model'], 'latencyMs' => 1, 'costMicros' => 0];
                }
            });
            $delegate = app(KnowledgeGate::class);
            $revoke = fn () => $this->revokeInWorker();
            $gate = new class($delegate, $revoke) implements KnowledgeGate
            {
                public int $checks = 0;

                public function __construct(private KnowledgeGate $delegate, private \Closure $revoke) {}

                public function assertAllowed(TenantContext $tenant, string $measurementId, array $frozenProfile): void
                {
                    $this->checks++;
                    if ($this->checks === 3) {
                        ($this->revoke)();
                    }
                    $this->delegate->assertAllowed($tenant, $measurementId, $frozenProfile);
                }
            };
            app()->instance(KnowledgeGate::class, $gate);
            $evaluation = app(EvidenceStore::class)->evaluate($answer);
            self::assertSame('', $this->revokeFailure, $this->revokeFailure);
            self::assertSame('unknown', $evaluation);
            self::assertSame(3, $gate->checks);
            $row = DB::table('measurement_answers')->where('id', $answer)->first();
            self::assertNull($row->fact);
            self::assertNull($row->evidence_signature);
            self::assertNotNull(DB::table('vision_knowledge_grants')->where('id', $this->grantId)->value('revoked_at'));
            $report = app(ReportPreparation::class)->snapshot($measurement);
            $payload = json_decode(DB::table('vision_reports')->where('id', $report)->value('payload'), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(0, $payload['metrics']['panel']['valid']);
            self::assertNull($payload['metrics']['slots'][0]['fact']);
            Http::assertNothingSent();
        });
    }

    #[Group('race')]
    public function test_concurrent_revocation_during_generation_discards_the_result(): void
    {
        $this->within(function (): void {
            $set = app(QuestionSets::class)->create([['id' => 'q1', 'text' => 'Вопрос', 'intent' => 'choose']]);
            $measurement = app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 1);
            $answer = DB::table('measurement_answers')->where('measurement_id', $measurement)->value('id');
            $revoke = fn () => $this->revokeInWorker();
            app()->instance(FakeDriver::class, new class($revoke) implements Driver
            {
                public function __construct(private \Closure $revoke) {}

                public function complete(array $body): array
                {
                    ($this->revoke)();

                    return ['content' => 'Рекомендую Test.', 'toolCalls' => [], 'usage' => ['promptTokens' => 1, 'completionTokens' => 1],
                        'model' => $body['model'], 'latencyMs' => 1, 'costMicros' => 0];
                }
            });
            try {
                $status = app(MeasurementRunner::class)->measure($answer);
            } catch (LogicException) {
                $status = 'exception';
            }
            self::assertSame('', $this->revokeFailure, $this->revokeFailure);
            self::assertSame('failed', $status);
            $row = DB::table('measurement_answers')->where('id', $answer)->first();
            self::assertNull($row->answer_text);
            self::assertNull($row->fact);
            self::assertSame('knowledge_unavailable', $row->error_code);
            Http::assertNothingSent();
        });
    }

    #[Group('race')]
    public function test_concurrent_answer_change_before_fact_save_is_rejected(): void
    {
        $this->within(function (): void {
            $set = app(QuestionSets::class)->create([['id' => 'q1', 'text' => 'Какие марки выбрать?', 'intent' => 'choose']]);
            $measurement = app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 1);
            $answer = DB::table('measurement_answers')->where('measurement_id', $measurement)->value('id');
            $this->fixture('Какие марки выбрать?', 'Рекомендую Test.');
            self::assertSame('ok', app(MeasurementRunner::class)->measure($answer));
            $candidate = ['brand_mentioned' => true, 'brand_position' => 1, 'brand_recommended' => true, 'mention_quote' => 'Рекомендую Test',
                'companies' => [['name' => 'Test', 'position' => 1, 'role' => 'supplier', 'is_brand' => true]],
                'tone' => 'positive', 'claims' => [], 'suspected_errors' => [], 'confidence' => 0.9];
            app()->instance(FakeDriver::class, new class($candidate) implements Driver
            {
                public function __construct(private array $candidate) {}

                public function complete(array $body): array
                {
                    return ['content' => json_encode($this->candidate, JSON_THROW_ON_ERROR), 'toolCalls' => [],
                        'usage' => ['promptTokens' => 1, 'completionTokens' => 1], 'model' => $body['model'], 'latencyMs' => 1, 'costMicros' => 0];
                }
            });
            $delegate = app(KnowledgeGate::class);
            $change = function () use ($answer): void {
                $process = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/change-answer-worker.php',
                    $answer, base_path(), DB::connection()->getDatabaseName()], base_path(), ['APP_ENV' => 'testing'], null, 45);
                $process->run();
                self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            };
            $gate = new class($delegate, $change) implements KnowledgeGate
            {
                public int $checks = 0;

                public function __construct(private KnowledgeGate $delegate, private \Closure $change) {}

                public function assertAllowed(TenantContext $tenant, string $measurementId, array $frozenProfile): void
                {
                    $this->checks++;
                    if ($this->checks === 3) {
                        ($this->change)();
                    }
                    $this->delegate->assertAllowed($tenant, $measurementId, $frozenProfile);
                }
            };
            app()->instance(KnowledgeGate::class, $gate);
            self::assertSame('unknown', app(EvidenceStore::class)->evaluate($answer));
            self::assertSame(3, $gate->checks);
            self::assertNull(DB::table('measurement_answers')->where('id', $answer)->value('fact'));
            Http::assertNothingSent();
        });
    }

    public function test_legacy_measurement_without_frozen_profile_stays_unknown(): void
    {
        $this->within(function (): void {
            $questions = [['id' => 'q1', 'text' => 'Вопрос', 'intent' => 'choose']];
            $set = app(QuestionSets::class)->create($questions);
            $id = (string) Str::ulid();
            DB::table('measurements')->insert(['id' => $id, 'organization_id' => $this->organization, 'brand_id' => $this->brand,
                'question_set_id' => $set, 'status' => 'completed', 'configuration' => json_encode(['questions' => $questions,
                    'question_set_checksum' => CanonicalJson::hash($questions), 'providers' => ['gemini' => 'google/gemini-2.5-flash'], 'runs' => 1, 'parser' => null], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now()]);
            $answer = (string) Str::ulid();
            DB::table('measurement_answers')->insert(['id' => $answer, 'organization_id' => $this->organization, 'brand_id' => $this->brand,
                'measurement_id' => $id, 'question_n' => 1, 'provider' => 'gemini', 'run_no' => 1, 'requested_model' => 'google/gemini-2.5-flash',
                'status' => 'ok', 'protocol_valid' => true, 'answer_text' => 'Старый ответ Test', 'created_at' => now(), 'updated_at' => now()]);
            self::assertSame('unknown', app(EvidenceStore::class)->evaluate($answer));
            $report = app(ReportPreparation::class)->snapshot($id);
            $payload = json_decode(DB::table('vision_reports')->where('id', $report)->value('payload'), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(0, $payload['metrics']['panel']['valid']);
            self::assertNull($payload['metrics']['slots'][0]['fact']);
            self::assertSame(0, DB::table('ai_calls')->count());
        });
    }

    private function revokeInWorker(): void
    {
        try {
            $process = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/revoke-worker.php',
                $this->organization, $this->brand, $this->ownerContext->actorId, $this->ownerContext->membershipId, $this->grantId,
                base_path(), DB::connection()->getDatabaseName()], base_path(), ['APP_ENV' => 'testing'], null, 45);
            $process->run();
            if ($process->getExitCode() !== 0 || trim($process->getOutput()) !== 'REVOCATION_OK') {
                $this->revokeFailure = 'Revoke worker failed: '.trim($process->getErrorOutput()).' exit='.$process->getExitCode();
                throw new LogicException('Revoke worker failed.');
            }
        } catch (\Throwable $exception) {
            $this->revokeFailure = $this->revokeFailure ?: 'Revoke worker process error: '.get_class($exception);
            throw $exception;
        }
    }

    private function assertTraceDoesNotContain(\Throwable $exception, string $marker): void
    {
        $contains = function (mixed $value, int $depth = 0) use (&$contains, $marker): bool {
            if ($depth > 20 || $value instanceof \SensitiveParameterValue) {
                return false;
            }
            if (is_string($value)) {
                return str_contains($value, $marker);
            }
            if ($value instanceof \stdClass) {
                $value = (array) $value;
            }
            if (is_array($value)) {
                foreach ($value as $item) {
                    if ($contains($item, $depth + 1)) {
                        return true;
                    }
                }
            }

            return false;
        };
        foreach ($exception->getTrace() as $frame) {
            self::assertFalse($contains($frame['args'] ?? []), 'A trace argument exposed the marker.');
        }
    }

    private function prepareKnowledge(): void
    {
        $owner = User::create(['name' => 'Owner', 'email' => 'owner-'.Str::lower(Str::random(12)).'@example.test', 'password' => 'test-only-password']);
        DB::table('organizations')->where('id', $this->organization)->update(['owner_user_id' => $owner->id]);
        $member = app(TenantContextRunner::class)->run(new TenantContext($this->organization, source: 'job'), fn () => Membership::create(['user_id' => $owner->id, 'role' => 'owner']));
        $context = new TenantContext($this->organization, $this->brand, $owner->id, $member->id);
        $this->ownerContext = $context;
        app(TenantContextRunner::class)->run($context, function () use ($context): void {
            $source = ['conditions' => [], 'facts' => [['id' => 'assortment', 'facts' => ['Широкий ассортимент']]], 'triggers' => [], 'guard_rules' => [], 'policy' => [], 'intents' => [], 'escalation' => [], 'persona' => [], 'fewshot' => [], 'closed_topics' => []];
            $repo = app(DatabaseKnowledgeRepository::class);
            $draft = $repo->createDraft($context, $source);
            $release = $repo->publish($context, $draft['id'], 1);
            $fact = DB::table('kn_facts as f')->join('kn_fact_groups as g', 'g.id', '=', 'f.group_id')->where('g.release_id', $release->releaseId())->value('f.logical_id');
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $this->organization, 'brand_id' => $this->brand, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('brands')->where('id', $this->brand)->update(['settings' => json_encode(['ai_data_policy' => ['knowledge_public' => ['any']]], JSON_THROW_ON_ERROR)]);
            $grant = app(GrantRegistry::class)->issue($context, $release->releaseId(), [$fact]);
            $this->grantId = $grant['id'];
        });
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
