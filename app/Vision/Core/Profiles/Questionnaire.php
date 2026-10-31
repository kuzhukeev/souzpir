<?php

namespace App\Vision\Core\Profiles;

use App\Ai\CanonicalJson;
use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Throwable;

/** Private account-scoped questionnaire and immutable profile versions. */
final class Questionnaire
{
    public function __construct(private readonly ProfileAccess $access) {}

    public function start(#[\SensitiveParameter] Request $request): array
    {
        return $this->run($request, function (TenantContext $context, User $user): array {
            $where = $this->tenant($context);
            $existing = DB::table('visibility_quizzes')->where($where)->where('status', 'draft')->lockForUpdate()->first();
            if ($existing) {
                return ['id' => $existing->id, 'version' => (int) $existing->version, 'revision' => (int) $existing->revision];
            }
            $id = (string) Str::ulid();
            $version = 1 + (int) DB::table('visibility_quizzes')->where($where)->max('version');
            DB::table('visibility_quizzes')->insert(['id' => $id, ...$where, 'version' => $version, 'revision' => 1,
                'status' => 'draft', 'current_step' => 1, 'answers' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            app(AuditWriter::class)->record($context, new AuditEntry('vision.quiz.created', 'visibility_quiz', $id));

            return ['id' => $id, 'version' => $version, 'revision' => 1];
        });
    }

    public function read(#[\SensitiveParameter] Request $request, string $quizId): array
    {
        return $this->run($request, function (TenantContext $context) use ($quizId): array {
            $row = $this->quiz($context, $quizId);

            return ['id' => $row->id, 'version' => (int) $row->version, 'revision' => (int) $row->revision,
                'status' => $row->status, 'current_step' => (int) $row->current_step, 'answers' => $this->decode($row->answers)];
        });
    }

    public function saveStep(#[\SensitiveParameter] Request $request, string $quizId, int $expectedRevision, int $step, #[\SensitiveParameter] array $values): int
    {
        QuizAnswers::validateStep($step, $values);

        return $this->run($request, function (TenantContext $context) use ($quizId, $expectedRevision, $step, $values): int {
            $row = $this->quiz($context, $quizId);
            $this->expectDraft($row, $expectedRevision);
            $answers = $this->decode($row->answers);
            $answers['step'.$step] = $values;
            $encoded = QuizAnswers::encodeDocument($answers);
            DB::table('visibility_quizzes')->where($this->tenant($context))->where('id', $quizId)->update([
                'answers' => $encoded, 'revision' => $expectedRevision + 1,
                'current_step' => min(8, $step + 1), 'updated_at' => now(),
            ]);
            app(AuditWriter::class)->record($context, new AuditEntry('vision.quiz.step_saved', 'visibility_quiz', $quizId, after: ['step' => $step]));

            return $expectedRevision + 1;
        });
    }

    /** Idempotent for a previously submitted quiz; never silently replaces a different revision. */
    public function submit(#[\SensitiveParameter] Request $request, string $quizId, int $expectedRevision): array
    {
        return $this->run($request, function (TenantContext $context, User $user) use ($quizId, $expectedRevision): array {
            $row = $this->quiz($context, $quizId);
            if ((int) $row->revision !== $expectedRevision) {
                throw new QuizConflict('Questionnaire was changed.');
            }
            if (in_array($row->status, ['submitted', 'superseded'], true)) {
                $previous = DB::table('brand_profiles')->where($this->tenant($context))->where('quiz_id', $quizId)->where('source', 'submission')->first();
                if (! $previous) {
                    throw new LogicException('Submitted profile is unavailable.');
                }

                return ['id' => $previous->id, 'version' => (int) $previous->version];
            }
            $this->expectDraft($row, $expectedRevision);
            $answers = $this->decode($row->answers);
            QuizAnswers::validateComplete($answers);
            $where = $this->tenant($context);
            DB::table('visibility_quizzes')->where($where)->where('status', 'submitted')->update(['status' => 'superseded', 'updated_at' => now()]);
            DB::table('visibility_quizzes')->where($where)->where('id', $quizId)->update(['status' => 'submitted', 'current_step' => 8,
                'submitted_at' => now(), 'submitted_by' => $user->id, 'updated_at' => now()]);
            $profile = $this->insertProfile($context, $user, $quizId, $answers, 'submission');
            app(AuditWriter::class)->record($context, new AuditEntry('vision.quiz.submitted', 'visibility_quiz', $quizId, after: ['profile_id' => $profile['id'], 'version' => $profile['version']]));

            return $profile;
        });
    }

    /** Edit a section by creating a new version, requiring the current profile ID. */
    public function edit(#[\SensitiveParameter] Request $request, string $expectedProfileId, int $step, #[\SensitiveParameter] array $values): array
    {
        QuizAnswers::validateStep($step, $values);

        return $this->run($request, function (TenantContext $context, User $user) use ($expectedProfileId, $step, $values): array {
            $current = DB::table('brand_profiles')->where($this->tenant($context))->orderByDesc('version')->first();
            if (! $current || $current->id !== $expectedProfileId) {
                throw new QuizConflict('Profile was changed.');
            }
            $answers = $this->checkedProfileAnswers($current);
            $answers['step'.$step] = $values;
            QuizAnswers::validateComplete($answers);
            $profile = $this->insertProfile($context, $user, $current->quiz_id, $answers, 'edit');
            app(AuditWriter::class)->record($context, new AuditEntry('vision.profile.updated', 'brand_profile', $profile['id'], after: ['version' => $profile['version'], 'step' => $step]));

            return $profile;
        });
    }

    public function profile(#[\SensitiveParameter] Request $request, string $profileId): array
    {
        return $this->run($request, function (TenantContext $context) use ($profileId): array {
            if (! Str::isUlid($profileId)) {
                throw new LogicException('Vision profile is unavailable.');
            }
            $row = DB::table('brand_profiles')->where($this->tenant($context))->where('id', $profileId)->first();
            if (! $row) {
                throw new LogicException('Vision profile is unavailable.');
            }

            return ['id' => $row->id, 'quiz_id' => $row->quiz_id, 'version' => (int) $row->version, 'checksum' => $row->checksum, 'answers' => $this->checkedProfileAnswers($row)];
        });
    }

    private function insertProfile(TenantContext $context, User $user, string $quizId, #[\SensitiveParameter] array $answers, string $source): array
    {
        $where = $this->tenant($context);
        $previous = DB::table('brand_profiles')->where($where)->orderByDesc('version')->first();
        $id = (string) Str::ulid();
        $version = $previous ? (int) $previous->version + 1 : 1;
        // JSONB normalizes numbers (including negative zero); hash its round-tripped representation.
        $encoded = QuizAnswers::encodeDocument($answers);
        $normalized = DB::selectOne('select cast(cast(? as jsonb) as text) as value', [$encoded]);
        $storedAnswers = $this->decode($normalized->value);
        DB::table('brand_profiles')->insert(['id' => $id, ...$where, 'quiz_id' => $quizId, 'previous_profile_id' => $previous?->id,
            'version' => $version, 'source' => $source, 'answers' => $encoded,
            'checksum' => CanonicalJson::hash($storedAnswers), 'created_by' => $user->id, 'created_at' => now()]);

        return ['id' => $id, 'version' => $version];
    }

    private function quiz(TenantContext $context, string $id): object
    {
        if (! Str::isUlid($id)) {
            throw new LogicException('Vision questionnaire is unavailable.');
        }

        return DB::table('visibility_quizzes')->where($this->tenant($context))->where('id', $id)->lockForUpdate()->first()
            ?? throw new LogicException('Vision questionnaire is unavailable.');
    }

    private function expectDraft(object $row, int $revision): void
    {
        if ($row->status !== 'draft' || $revision < 1 || (int) $row->revision !== $revision) {
            throw new QuizConflict('Questionnaire was changed.');
        }
    }

    private function checkedProfileAnswers(#[\SensitiveParameter] object $row): array
    {
        $answers = $this->decode($row->answers);
        if (! is_string($row->checksum) || ! hash_equals($row->checksum, CanonicalJson::hash($answers))) {
            throw new LogicException('Vision profile is unavailable.');
        }

        return $answers;
    }

    private function decode(#[\SensitiveParameter] string $json): array
    {
        if (strlen($json) > 262144) {
            throw new LogicException('Vision questionnaire is unavailable.');
        }
        $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new LogicException('Vision questionnaire is unavailable.');
        }

        return $decoded;
    }

    private function tenant(TenantContext $context): array
    {
        return ['organization_id' => $context->organizationId, 'brand_id' => $context->brandId];
    }

    private function run(#[\SensitiveParameter] Request $request, #[\SensitiveParameter] Closure $action): mixed
    {
        try {
            return $this->access->run($request, $action);
        } catch (QuizConflict) {
            throw new QuizConflict('Questionnaire was changed.');
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('Invalid questionnaire data.');
        } catch (Throwable) {
            throw new LogicException('Vision questionnaire is unavailable.');
        }
    }
}
