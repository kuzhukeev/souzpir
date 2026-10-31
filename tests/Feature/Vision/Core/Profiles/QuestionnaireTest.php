<?php

namespace Tests\Feature\Vision\Core\Profiles;

use App\Ai\CanonicalJson;
use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Seller\Knowledge\DatabaseKnowledgeRepository;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\Core\MeasurementRunner;
use App\Vision\Core\Profiles\Questionnaire;
use App\Vision\Core\Profiles\QuizAnswers;
use App\Vision\Core\Profiles\QuizConflict;
use App\Vision\Core\QuestionSets;
use App\Vision\Evidence\Contracts\KnowledgeGate;
use App\Vision\KnowledgeBridge\PublishedKnowledgeBridge;
use App\Vision\KnowledgeBridge\SelfDeclaredProfile;
use App\Vision\KnowledgeGrants\GrantRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class QuestionnaireTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        // Guard the actual checkout and database before DatabaseMigrations calls migrate:fresh.
        $root = str_replace('\\', '/', dirname(__DIR__, 5));
        $testDb = config('runtime.test_database');
        if (str_replace('\\', '/', base_path()) !== $root || ! is_string($testDb)
            || ! preg_match('/(?:^|_)test(?:_|$)/', $testDb) || $testDb === config('runtime.preview_database')
            || DB::connection()->getDatabaseName() !== $testDb
            || ! str_starts_with(str_replace('\\', '/', storage_path()), $root.'/.local/runtime/testing/')
            || ! str_starts_with(str_replace('\\', '/', app()->getCachedConfigPath()), $root.'/.local/runtime/testing/')) {
            throw new \RuntimeException('Refusing migrations outside the dedicated test checkout.');
        }
        $state = DB::selectOne('select current_database() as db, current_user as role, inet_server_addr() as host, inet_server_port() as port, rolsuper, rolcreatedb, rolcreaterole from pg_roles where rolname = current_user');
        if ($state->db !== $testDb || (! str_starts_with((string) $state->host, '127.') && $state->host !== '::1')
            || (int) $state->port !== (int) config('database.connections.pgsql.port') || $state->rolsuper || $state->rolcreatedb || $state->rolcreaterole) {
            throw new \RuntimeException('Refusing migrations on an unsafe PostgreSQL instance.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function fixture(string $role = 'owner'): array
    {
        $user = User::create(['name' => 'Profile fixture', 'email' => Str::lower(Str::random(12)).'@example.test', 'password' => 'test-only-password']);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->refresh();
        $org = Organization::create(['name' => 'Fixture org', 'owner_user_id' => $user->id]);
        [$member, $brand, $other] = app(TenantContextRunner::class)->run(new TenantContext($org->id), fn () => [
            Membership::create(['user_id' => $user->id, 'role' => $role]), Brand::create(['name' => 'Fixture brand']), Brand::create(['name' => 'Other brand']),
        ]);
        foreach ([$brand, $other] as $entry) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $entry->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        Auth::login($user);
        session()->start();
        session()->put('auth.version', $user->auth_version);
        session()->put('brand_id', $brand->id);
        $request = Request::create('/vision/quiz', 'POST');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => $user);
        $this->app->instance('request', $request);
        app(AuthSessionRegistry::class)->issue($request, $user, $user->auth_version);
        $ctx = new TenantContext($org->id, $brand->id, $user->id, $member->id);

        return compact('user', 'org', 'brand', 'other', 'member', 'ctx', 'request');
    }

    private function within(TenantContext $ctx, \Closure $action): mixed
    {
        return app(TenantContextRunner::class)->run($ctx, $action);
    }

    private function submitted(array $fixture): array
    {
        return $this->within($fixture['ctx'], function () use ($fixture): array {
            $service = app(Questionnaire::class);
            $draft = $service->start($fixture['request']);
            $answers = [
                1 => ['brand_name' => 'Synthetic brand', 'site' => 'https://example.test'],
                2 => ['aliases' => ['Synthetic']], 3 => ['category' => 'Test category'],
                4 => ['competitors' => [['name' => 'Example rival', 'site' => null]]],
                5 => ['facts' => ['Synthetic user assertion']], 6 => ['presence' => []], 7 => ['questions' => []],
            ];
            $revision = $draft['revision'];
            foreach ($answers as $step => $value) {
                $revision = $service->saveStep($fixture['request'], $draft['id'], $revision, $step, $value);
            }

            return [$draft['id'], $revision, $service->submit($fixture['request'], $draft['id'], $revision)];
        });
    }

    public function test_submit_is_idempotent_and_edited_profiles_preserve_old_snapshot(): void
    {
        $f = $this->fixture();
        [$quiz, $revision, $profile] = $this->submitted($f);
        $this->within($f['ctx'], function () use ($f, $quiz, $revision, $profile): void {
            $service = app(Questionnaire::class);
            self::assertSame($profile, $service->submit($f['request'], $quiz, $revision));
            $before = app(SelfDeclaredProfile::class)->read($f['request'], $profile['id']);
            self::assertSame('example.test', $before['brand_domain']);
            self::assertFalse($before['facts'][0]['verifiable']);
            self::assertSame('self_declared', $before['facts'][0]['source']);
            $second = $service->edit($f['request'], $profile['id'], 2, ['aliases' => ['New alias']]);
            self::assertSame(2, $second['version']);
            self::assertSame($before, app(SelfDeclaredProfile::class)->read($f['request'], $profile['id']));
            self::assertSame(['New alias'], app(SelfDeclaredProfile::class)->read($f['request'], $second['id'])['aliases']);
            try {
                $service->edit($f['request'], $profile['id'], 2, ['aliases' => ['Lost update']]);
                self::fail('Stale version accepted.');
            } catch (QuizConflict) {
                self::assertTrue(true);
            }
            try {
                app(SelfDeclaredProfile::class)->forExternalAi($f['request'], $profile['id']);
                self::fail('Self-declared profile sent without a grant.');
            } catch (LogicException $e) {
                self::assertSame('Self-declared profile is not authorized for AI.', $e->getMessage());
            }
        });
        self::assertSame(2, DB::table('brand_profiles')->count());
        self::assertStringNotContainsString('Synthetic user assertion', json_encode(DB::table('audit_logs')->get(), JSON_THROW_ON_ERROR));
        Http::assertNothingSent();
    }

    public function test_stale_draft_and_another_brand_are_rejected(): void
    {
        $f = $this->fixture();
        $this->within($f['ctx'], function () use ($f): void {
            $service = app(Questionnaire::class);
            $draft = $service->start($f['request']);
            self::assertSame($draft, $service->start($f['request']));
            self::assertSame(2, $service->saveStep($f['request'], $draft['id'], 1, 1, ['brand_name' => 'A']));
            try {
                $service->saveStep($f['request'], $draft['id'], 1, 1, ['brand_name' => 'B']);
                self::fail('Stale write accepted.');
            } catch (QuizConflict) {
                self::assertSame('A', $service->read($f['request'], $draft['id'])['answers']['step1']['brand_name']);
            }
            $otherCtx = new TenantContext($f['org']->id, $f['other']->id, $f['user']->id, $f['member']->id);
            session()->put('brand_id', $f['other']->id);
            $this->within($otherCtx, function () use ($f, $draft, $service): void {
                try {
                    $service->read($f['request'], $draft['id']);
                    self::fail('Cross-brand quiz was read.');
                } catch (LogicException) {
                    self::assertTrue(true);
                }
            });
        });
    }

    public function test_profile_immutable_and_candidate_cannot_reference_a_foreign_tenant(): void
    {
        $f = $this->fixture();
        [$quiz, , $profile] = $this->submitted($f);
        try {
            DB::transaction(fn () => DB::table('brand_profiles')->where('id', $profile['id'])->update(['answers' => '{}']));
            self::fail('Profile update accepted.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
        try {
            DB::transaction(fn () => DB::table('question_generation_batches')->insert([
                'id' => (string) Str::ulid(), 'organization_id' => $f['org']->id, 'brand_id' => $f['other']->id,
                'quiz_id' => $quiz, 'profile_id' => $profile['id'], 'status' => 'blocked', 'created_by' => $f['user']->id,
                'created_at' => now(), 'updated_at' => now(),
            ]));
            self::fail('Cross-brand profile FK accepted.');
        } catch (QueryException) {
            self::assertSame(0, DB::table('question_generation_batches')->count());
        }
    }

    public function test_session_brand_header_role_and_membership_are_rechecked(): void
    {
        $f = $this->fixture();
        $this->within($f['ctx'], function () use ($f): void {
            $service = app(Questionnaire::class);
            $draft = $service->start($f['request']);
            $f['request']->headers->set('X-Brand-Id', $f['other']->id);
            try {
                $service->read($f['request'], $draft['id']);
                self::fail('Mismatched brand header accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            $f['request']->headers->remove('X-Brand-Id');
            session()->put('brand_id', $f['other']->id);
            try {
                $service->saveStep($f['request'], $draft['id'], 1, 1, ['brand_name' => 'Bad']);
                self::fail('Stale selected brand accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            session()->put('brand_id', $f['brand']->id);
            session()->put('auth.version', $f['user']->auth_version + 1);
            try {
                $service->read($f['request'], $draft['id']);
                self::fail('Stale session accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            session()->put('auth.version', $f['user']->auth_version);
            DB::table('memberships')->where('id', $f['member']->id)->update(['role' => 'viewer']);
            foreach ([fn () => $service->read($f['request'], $draft['id']), fn () => $service->saveStep($f['request'], $draft['id'], 1, 1, ['brand_name' => 'Bad'])] as $attempt) {
                try {
                    $attempt();
                    self::fail('Viewer may not access quiz/profile.');
                } catch (LogicException) {
                    self::assertTrue(true);
                }
            }
            DB::table('memberships')->where('id', $f['member']->id)->update(['status' => 'disabled']);
            try {
                $service->read($f['request'], $draft['id']);
                self::fail('Disabled member accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        });
    }

    public function test_existing_published_grant_is_not_replaced_by_user_profile_edits(): void
    {
        $f = $this->fixture();
        $this->within($f['ctx'], function () use ($f): void {
            $source = ['conditions' => [], 'facts' => [['id' => 'assortment', 'facts' => ['Published safe fact']]], 'triggers' => [], 'guard_rules' => [], 'policy' => [], 'intents' => [], 'escalation' => [], 'persona' => [], 'fewshot' => [], 'closed_topics' => []];
            $store = app(DatabaseKnowledgeRepository::class);
            $draft = $store->createDraft($f['ctx'], $source);
            $release = $store->publish($f['ctx'], $draft['id'], 1);
            $fact = DB::table('kn_facts as f')->join('kn_fact_groups as g', 'g.id', '=', 'f.group_id')->where('g.release_id', $release->releaseId())->value('f.logical_id');
            DB::table('brands')->where('id', $f['brand']->id)->update(['settings' => json_encode(['ai_data_policy' => ['knowledge_public' => ['any']]], JSON_THROW_ON_ERROR)]);
            $grant = app(GrantRegistry::class)->issue($f['ctx'], $release->releaseId(), [$fact]);
            $published = DB::transaction(fn () => app(PublishedKnowledgeBridge::class)->buildProfile($f['ctx']));
            config(['runtime.ai_driver' => 'fake', 'features.E8-01.defaults.parser' => ['model' => 'google/gemini-2.5-flash', 'temperature' => 0.0, 'max_tokens' => 1200]]);
            $set = app(QuestionSets::class)->create([['id' => 'q1', 'text' => 'Вопрос', 'intent' => 'choose']]);
            $runner = app(MeasurementRunner::class);
            $measurement = $runner->start($set, ['gemini' => 'google/gemini-2.5-flash'], 1);
            $snapshot = json_decode(DB::table('measurements')->where('id', $measurement)->value('profile_snapshot'), true, 64, JSON_THROW_ON_ERROR);
            self::assertSame(CanonicalJson::hash($published), CanonicalJson::hash($snapshot));
            app(KnowledgeGate::class)->assertAllowed($f['ctx'], $measurement, $snapshot);
            [$quiz, $revision, $profile] = $this->submitted($f);
            $withProfile = $runner->start($set, ['gemini' => 'google/gemini-2.5-flash'], 1);
            $frozen = json_decode(DB::table('measurements')->where('id', $withProfile)->value('profile_snapshot'), true, 64, JSON_THROW_ON_ERROR);
            self::assertSame($profile['id'], $frozen['self_declared']['profile_id']);
            self::assertSame('Synthetic brand', $frozen['self_declared']['name']);
            self::assertFalse($frozen['self_declared']['facts'][0]['verifiable']);
            app(Questionnaire::class)->edit($f['request'], $profile['id'], 5, ['facts' => ['User asserted this']]);
            $after = DB::transaction(fn () => app(PublishedKnowledgeBridge::class)->buildProfile($f['ctx']));
            self::assertNotSame($frozen['self_declared']['profile_id'], $after['self_declared']['profile_id']);
            self::assertSame('Synthetic user assertion', $frozen['self_declared']['facts'][0]['value']);
            self::assertSame($grant['id'], $after['knowledge']['grant_id']);
            app(KnowledgeGate::class)->assertAllowed($f['ctx'], $measurement, $snapshot);
            try {
                app(KnowledgeGate::class)->assertAllowed($f['ctx'], $withProfile, $frozen);
                self::fail('Self-declared data were released without outgoing payload protection.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            $answer = DB::table('measurement_answers')->where('measurement_id', $withProfile)->value('id');
            try {
                $runner->measure($answer);
                self::fail('Measurement ran before outgoing payload protection.');
            } catch (LogicException) {
                self::assertSame('pending', DB::table('measurement_answers')->where('id', $answer)->value('status'));
                self::assertSame(0, DB::table('ai_calls')->count());
            }
            self::assertFalse(method_exists(SelfDeclaredProfile::class, 'forSnapshot'));
            foreach (['stale', 'brand', 'viewer'] as $denial) {
                if ($denial === 'stale') {
                    session()->put('auth.version', $f['user']->auth_version + 1);
                }
                if ($denial === 'brand') {
                    $f['request']->headers->set('X-Brand-Id', $f['other']->id);
                }
                if ($denial === 'viewer') {
                    DB::table('memberships')->where('id', $f['member']->id)->update(['role' => 'viewer']);
                }
                try {
                    DB::transaction(fn () => app(PublishedKnowledgeBridge::class)->buildProfile($f['ctx']));
                    self::fail('Bridge returned private profile without the required '.$denial.' boundary.');
                } catch (LogicException) {
                    self::assertTrue(true);
                } finally {
                    session()->put('auth.version', $f['user']->auth_version);
                    $f['request']->headers->remove('X-Brand-Id');
                    DB::table('memberships')->where('id', $f['member']->id)->update(['role' => 'owner']);
                }
            }
            $job = new TenantContext($f['org']->id, $f['brand']->id, source: 'job');
            $jobSnapshot = $this->within($job, fn () => DB::transaction(fn () => app(PublishedKnowledgeBridge::class)->buildProfile($job)));
            self::assertSame($after['self_declared']['profile_id'], $jobSnapshot['self_declared']['profile_id']);
            app(GrantRegistry::class)->revoke($f['ctx'], $grant['id']);
            try {
                app(KnowledgeGate::class)->assertAllowed($f['ctx'], $measurement, $snapshot);
                self::fail('Revoked grant accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        });
    }

    public function test_two_connections_cannot_overwrite_the_same_revision(): void
    {
        $f = $this->fixture();
        $draft = $this->within($f['ctx'], fn () => app(Questionnaire::class)->start($f['request']));
        $otherUser = User::create(['name' => 'Another editor', 'email' => 'second-editor@example.test', 'password' => 'test-only-password']);
        $otherUser->forceFill(['email_verified_at' => now()])->save();
        $otherUser->refresh();
        $member = app(TenantContextRunner::class)->run(new TenantContext($f['org']->id), fn () => Membership::create(['user_id' => $otherUser->id, 'role' => 'admin']));
        $markers = [bin2hex(random_bytes(12)), bin2hex(random_bytes(12))];
        $worker = base_path('tests/Feature/Vision/Core/Profiles/fixtures/concurrent-save.php');
        $env = ['APP_ENV' => 'testing', 'APP_BASE_PATH' => base_path()];
        $first = new Process([PHP_BINARY, $worker, $f['user']->id, $f['org']->id, $f['brand']->id, $f['member']->id, $draft['id'], $markers[0]], base_path(), $env, null, 20);
        $second = new Process([PHP_BINARY, $worker, $otherUser->id, $f['org']->id, $f['brand']->id, $member->id, $draft['id'], $markers[1]], base_path(), $env, null, 20);
        DB::beginTransaction();
        try {
            DB::table('brands')->where('id', $f['brand']->id)->lockForUpdate()->first();
            $first->start();
            $second->start();
            $deadline = microtime(true) + 10;
            do {
                if (is_file(base_path('.local/runtime/testing/'.$markers[0].'.ready')) && is_file(base_path('.local/runtime/testing/'.$markers[1].'.ready'))) {
                    break;
                }
                usleep(50000);
            } while (microtime(true) < $deadline);
            self::assertFileExists(base_path('.local/runtime/testing/'.$markers[0].'.ready'));
            self::assertFileExists(base_path('.local/runtime/testing/'.$markers[1].'.ready'));
            DB::commit();
            $first->wait();
            $second->wait();
            self::assertTrue($first->isSuccessful(), $first->getErrorOutput());
            self::assertTrue($second->isSuccessful(), $second->getErrorOutput());
            $outcomes = [trim($first->getOutput()), trim($second->getOutput())];
            sort($outcomes);
            self::assertSame(['conflict', 'saved'], $outcomes);
            self::assertSame(2, DB::table('visibility_quizzes')->where('id', $draft['id'])->value('revision'));
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ([$first, $second] as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach ($markers as $marker) {
                @unlink(base_path('.local/runtime/testing/'.$marker.'.ready'));
            }
        }
    }

    public function test_database_rejects_duplicate_draft_and_modified_submitted_answers(): void
    {
        $f = $this->fixture();
        $draft = $this->within($f['ctx'], fn () => app(Questionnaire::class)->start($f['request']));
        try {
            DB::transaction(fn () => DB::table('visibility_quizzes')->insert([
                'id' => (string) Str::ulid(), 'organization_id' => $f['org']->id, 'brand_id' => $f['brand']->id,
                'version' => 2, 'revision' => 1, 'status' => 'draft', 'current_step' => 1, 'answers' => '{}', 'created_at' => now(), 'updated_at' => now(),
            ]));
            self::fail('Second draft accepted.');
        } catch (QueryException) {
            self::assertSame(1, DB::table('visibility_quizzes')->count());
        }
        [$quiz] = $this->submitted($f);
        try {
            DB::transaction(fn () => DB::table('visibility_quizzes')->where('id', $quiz)->update(['answers' => '{}']));
            self::fail('Submitted quiz was modified.');
        } catch (QueryException) {
            self::assertNotSame('{}', DB::table('visibility_quizzes')->where('id', $quiz)->value('answers'));
        }
    }

    public function test_editor_can_use_only_explicitly_granted_brand(): void
    {
        $f = $this->fixture('member');
        DB::table('memberships')->where('id', $f['member']->id)->update(['brand_ids' => json_encode([$f['brand']->id], JSON_THROW_ON_ERROR)]);
        $draft = $this->within($f['ctx'], fn () => app(Questionnaire::class)->start($f['request']));
        self::assertNotEmpty($draft['id']);
        session()->put('brand_id', $f['other']->id);
        $foreign = new TenantContext($f['org']->id, $f['other']->id, $f['user']->id, $f['member']->id);
        try {
            $this->within($foreign, fn () => app(Questionnaire::class)->start($f['request']));
            self::fail('Ungranted brand accepted.');
        } catch (LogicException) {
            self::assertTrue(true);
        }
        self::assertSame(1, DB::table('visibility_quizzes')->count());
    }

    public function test_failed_audit_rolls_back_step_and_redacts_error(): void
    {
        $f = $this->fixture();
        $this->within($f['ctx'], function () use ($f): void {
            $service = app(Questionnaire::class);
            $draft = $service->start($f['request']);
            $marker = 'synthetic sensitive questionnaire detail';
            $audit = $this->createStub(AuditWriter::class);
            $audit->method('record')->willThrowException(new \RuntimeException($marker));
            $this->app->instance(AuditWriter::class, $audit);
            try {
                $service->saveStep($f['request'], $draft['id'], 1, 1, ['brand_name' => $marker]);
                self::fail('Expected a sanitized failure.');
            } catch (LogicException $e) {
                self::assertSame('Vision questionnaire is unavailable.', $e->getMessage());
                self::assertNull($e->getPrevious());
                self::assertStringNotContainsString($marker, $e->getTraceAsString());
            }
            $fresh = $service->read($f['request'], $draft['id']);
            self::assertSame(1, $fresh['revision']);
            self::assertSame([], $fresh['answers']);
        });
    }

    public function test_submitted_projection_validation_and_exception_traces_are_safe(): void
    {
        $valid = ['step1' => ['brand_name' => 'Brand', 'site' => 'https://example.test'],
            'step2' => ['aliases' => ['Alias']], 'step3' => ['category' => 'Category'],
            'step4' => ['competitors' => [['name' => 'Rival', 'site' => 'https://rival.test']]],
            'step5' => ['facts' => ['Assertion']], 'step6' => ['presence' => []], 'step7' => ['questions' => []]];
        QuizAnswers::validateComplete($valid);
        self::assertSame('Brand', SelfDeclaredProfile::projectAnswers($valid)['name']);
        $invalid = [
            ['step1', ['brand_name' => 'Brand', 'site' => 'javascript:secret']],
            ['step1', ['brand_name' => 'Brand', 'site' => 'https://user:pass@example.test']],
            ['step2', ['aliases' => array_fill(0, 51, 'Alias')]],
            ['step3', ['category' => ['invalid']]],
            ['step4', ['competitors' => [['name' => 'Rival', 'site' => 'javascript:secret']]]],
            ['step5', ['facts' => ['valid', 123]]],
            ['step6', ['presence' => str_repeat('x', 32001)]],
            ['step7', ['questions' => array_fill(0, 16, 'q')]],
        ];
        foreach ($invalid as [$step, $value]) {
            $answers = $valid;
            $answers[$step] = $value;
            try {
                QuizAnswers::validateComplete($answers);
                self::fail('Invalid submitted profile accepted: '.$step);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $f = $this->fixture();
        $this->within($f['ctx'], function () use ($f, $valid): void {
            $service = app(Questionnaire::class);
            $draft = $service->start($f['request']);
            // Incomplete drafts may temporarily contain an invalid URL, but submission may not.
            $revision = $draft['revision'];
            foreach ($valid as $step => $values) {
                $number = (int) substr($step, 4);
                if ($number === 1) {
                    $values['site'] = 'javascript:private-marker';
                }
                $revision = $service->saveStep($f['request'], $draft['id'], $revision, $number, $values);
            }
            $previous = ini_get('zend.exception_ignore_args');
            ini_set('zend.exception_ignore_args', '0');
            try {
                try {
                    $service->submit($f['request'], $draft['id'], $revision);
                    self::fail('Invalid draft was submitted.');
                } catch (\InvalidArgumentException $e) {
                    self::assertStringNotContainsString('private-marker', $e->getTraceAsString());
                    $this->assertTraceDoesNotContain($e->getTrace(), 'private-marker');
                    self::assertNull($e->getPrevious());
                    self::assertSame(0, DB::table('brand_profiles')->count());
                }
                try {
                    $service->saveStep($f['request'], $draft['id'], $revision, 6, ['presence' => str_repeat('sensitive-save-marker', 2000)]);
                    self::fail('Oversized draft accepted.');
                } catch (\InvalidArgumentException $e) {
                    self::assertStringNotContainsString('sensitive-save-marker', $e->getTraceAsString());
                    $this->assertTraceDoesNotContain($e->getTrace(), 'sensitive-save-marker');
                    self::assertNull($e->getPrevious());
                }
            } finally {
                ini_set('zend.exception_ignore_args', (string) $previous);
            }
        });
    }

    public function test_jsonb_numeric_normalization_preserves_checksums_across_submit_and_edit(): void
    {
        $f = $this->fixture();
        $this->within($f['ctx'], function () use ($f): void {
            $service = app(Questionnaire::class);
            $draft = $service->start($f['request']);
            $revision = 1;
            for ($step = 1; $step <= 7; $step++) {
                $values = match ($step) {
                    1 => ['brand_name' => 'Brand'],
                    6 => ['one' => 1.0, 'negative_zero' => -0.0, 'large_integer' => PHP_INT_MAX, 'large_float' => 1.25e25],
                    default => [],
                };
                $revision = $service->saveStep($f['request'], $draft['id'], $revision, $step, $values);
            }
            $profile = $service->submit($f['request'], $draft['id'], $revision);
            $first = $service->profile($f['request'], $profile['id']);
            self::assertSame(1.0, $first['answers']['step6']['one']);
            self::assertSame(0.0, $first['answers']['step6']['negative_zero']);
            self::assertSame(PHP_INT_MAX, $first['answers']['step6']['large_integer']);
            self::assertEquals(1.25e25, $first['answers']['step6']['large_float']);
            $edited = $service->edit($f['request'], $profile['id'], 3, ['category' => 'Edited']);
            $second = $service->profile($f['request'], $edited['id']);
            self::assertSame($first['answers']['step6'], $second['answers']['step6']);
            self::assertSame($first, $service->profile($f['request'], $profile['id']));
        });
    }

    public function test_full_document_depth_is_checked_before_saving(): void
    {
        $f = $this->fixture();
        $this->within($f['ctx'], function () use ($f): void {
            $service = app(Questionnaire::class);
            $draft = $service->start($f['request']);
            $nested = 'leaf';
            for ($i = 0; $i < 31; $i++) {
                $nested = ['nested' => $nested];
            }
            // The section alone fits the depth bound, but its enclosing document does not.
            QuizAnswers::validateStep(6, $nested);
            try {
                $service->saveStep($f['request'], $draft['id'], 1, 6, $nested);
                self::fail('Unreadable document was saved.');
            } catch (\InvalidArgumentException) {
                $saved = $service->read($f['request'], $draft['id']);
                self::assertSame(1, $saved['revision']);
                self::assertSame([], $saved['answers']);
            }
        });
    }

    private function assertTraceDoesNotContain(array $trace, string $marker): void
    {
        $check = function (mixed $value) use (&$check, $marker): void {
            if (is_string($value)) {
                self::assertStringNotContainsString($marker, $value);
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    $check($item);
                }
            }
            // PHP masks marked arguments with SensitiveParameterValue objects.
        };
        foreach ($trace as $frame) {
            $check($frame['args'] ?? []);
        }
    }
}
