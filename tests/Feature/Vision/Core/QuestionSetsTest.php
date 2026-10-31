<?php

namespace Tests\Feature\Vision\Core;

use App\Models\Membership;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\Core\QuestionSets;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class QuestionSetsTest extends TestCase
{
    use DatabaseMigrations;

    #[DataProvider('historicalSizes')]
    public function test_real_question_set_size_and_content_are_saved(int $size): void
    {
        [$organization, $brand] = $this->cabinet();
        $questions = array_map(fn (int $n): array => ['id' => 'q'.$n, 'text' => 'Вопрос '.$n, 'intent' => 'choose'], range(1, $size));
        app(TenantContextRunner::class)->run(new TenantContext($organization, $brand, source: 'job'), function () use ($size, $questions): void {
            $first = app(QuestionSets::class)->create($questions);
            $second = app(QuestionSets::class)->create($questions);
            $rows = DB::table('question_sets')->whereIn('id', [$first, $second])->orderBy('version')->get();
            self::assertSame([1, 2], $rows->pluck('version')->all());
            self::assertSame($size, $rows[0]->size);
            self::assertSame($questions, json_decode($rows[0]->questions, true, flags: JSON_THROW_ON_ERROR));
            self::assertSame($rows[0]->checksum, $rows[1]->checksum);
        });
    }

    public static function historicalSizes(): array
    {
        return [[12], [20], [30]];
    }

    public function test_active_brand_is_required_and_duplicate_questions_are_rejected(): void
    {
        [$organization, $brand] = $this->cabinet();
        $question = ['id' => 'q1', 'text' => 'Вопрос', 'intent' => 'choose'];
        app(TenantContextRunner::class)->run(new TenantContext($organization, $brand, source: 'job'), function () use ($question): void {
            try {
                app(QuestionSets::class)->create([$question, $question]);
                self::fail('Duplicate question must be rejected.');
            } catch (InvalidArgumentException) {
                self::assertSame(0, DB::table('question_sets')->count());
            }
        });
        $this->expectException(\LogicException::class);
        app(QuestionSets::class)->create([$question]);
    }

    public function test_oversized_question_count_bytes_and_invalid_configuration_fail_before_writes(): void
    {
        [$organization, $brand] = $this->cabinet();
        app(TenantContextRunner::class)->run(new TenantContext($organization, $brand, source: 'job'), function (): void {
            $questions = array_map(fn ($n) => ['id' => 'q'.$n, 'text' => 'Вопрос', 'intent' => 'choose'], range(1, 101));
            try {
                app(QuestionSets::class)->create($questions);
                self::fail('Oversized set accepted.');
            } catch (InvalidArgumentException) {
                self::assertSame(0, DB::table('question_sets')->count());
            }
            config(['features.E8-01.defaults.workload.max_questions_bytes' => 20]);
            try {
                app(QuestionSets::class)->create([['id' => 'q1', 'text' => 'Вопрос', 'intent' => 'choose']]);
                self::fail('Oversized payload accepted.');
            } catch (InvalidArgumentException) {
                self::assertSame(0, DB::table('question_sets')->count());
            }
            config(['features.E8-01.defaults.workload.max_questions' => null]);
            try {
                app(QuestionSets::class)->create([['id' => 'q1', 'text' => 'Вопрос', 'intent' => 'choose']]);
                self::fail('Invalid safeguards accepted.');
            } catch (\LogicException) {
                self::assertSame(0, DB::table('question_sets')->count());
            }
        });
    }

    public function test_user_context_requires_active_membership_with_brand_access(): void
    {
        [$organization, $brand] = $this->cabinet();
        $user = User::create(['name' => 'Fixture', 'email' => Str::random(16).'@example.test', 'password' => 'test-only-password']);
        $member = app(TenantContextRunner::class)->run(new TenantContext($organization, source: 'job'), fn () => Membership::create(['user_id' => $user->id, 'role' => 'member', 'brand_ids' => [$brand]]));
        $context = new TenantContext($organization, $brand, $user->id, $member->id);
        $question = [['id' => 'q1', 'text' => 'Вопрос', 'intent' => 'choose']];
        app(TenantContextRunner::class)->run($context, fn () => app(QuestionSets::class)->create($question));
        DB::table('memberships')->where('id', $member->id)->update(['status' => 'disabled']);
        try {
            app(TenantContextRunner::class)->run($context, fn () => app(QuestionSets::class)->create($question));
            self::fail('Disabled membership accepted.');
        } catch (\LogicException) {
            self::assertSame(1, DB::table('question_sets')->count());
        }
        DB::table('memberships')->where('id', $member->id)->update(['status' => 'active', 'brand_ids' => json_encode([])]);
        try {
            app(TenantContextRunner::class)->run($context, fn () => app(QuestionSets::class)->create($question));
            self::fail('Foreign brand accepted.');
        } catch (\LogicException) {
            self::assertSame(1, DB::table('question_sets')->count());
        }
    }

    #[DataProvider('tenantTargets')]
    public function test_archive_or_disable_during_lock_wait_prevents_creation(string $target): void
    {
        [$organization, $brand] = $this->cabinet();
        DB::beginTransaction();
        $process = null;
        try {
            DB::table($target)->where('id', $target === 'organizations' ? $organization : $brand)->lockForUpdate()->first();
            $process = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/question-set-worker.php',
                $organization, $brand, base_path(), DB::connection()->getDatabaseName()], base_path(), ['APP_ENV' => 'testing'], null, 40);
            $process->start();
            $waiting = false;
            for ($i = 0; $i < 200 && $process->isRunning(); $i++) {
                DB::statement('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_stat_activity')->where('application_name', 'vision_question_lock_test')->where('wait_event_type', 'Lock')->exists();
                if ($waiting) {
                    break;
                }
                usleep(25000);
            }
            self::assertTrue($waiting, 'Worker must reach the tenant row lock.');
            DB::table($target)->where('id', $target === 'organizations' ? $organization : $brand)
                ->update(['status' => $target === 'organizations' ? 'disabled' : 'archived']);
            DB::commit();
            $process->wait();
            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            self::assertSame("READY\nDENIED", trim(str_replace("\r\n", "\n", $process->getOutput())));
            self::assertSame(0, DB::table('question_sets')->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($process?->isRunning()) {
                $process->stop();
            }
        }
    }

    public static function tenantTargets(): array
    {
        return [['organizations'], ['brands']];
    }

    private function cabinet(): array
    {
        $organization = (string) Str::ulid();
        $brand = (string) Str::ulid();
        DB::table('organizations')->insert(['id' => $organization, 'name' => 'Test', 'status' => 'active', 'settings' => '{}']);
        DB::table('brands')->insert(['id' => $brand, 'organization_id' => $organization, 'name' => 'Test', 'status' => 'active', 'theme' => 'neutral', 'settings' => '{}']);

        return [$organization, $brand];
    }
}
