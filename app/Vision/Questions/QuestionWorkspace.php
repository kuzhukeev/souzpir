<?php

namespace App\Vision\Questions;

use App\Ai\CanonicalJson;
use App\Ai\ProviderCatalog;
use App\Models\Membership;
use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Organizations\ModuleEntitlements;
use App\Shell\Plans\Contracts\PlanLimits;
use App\Shell\Plans\LimitEnforcer;
use App\Tenancy\TenantContext;
use App\Vision\Core\Profiles\ProfileAccess;
use App\Vision\Core\Profiles\Questionnaire;
use App\Vision\Core\QuestionSets;
use App\Vision\Evidence\VisibilityProfile;
use App\Vision\KnowledgeBridge\SelfDeclaredProfile;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class QuestionWorkspace
{
    private const INTENTS = ['choose', 'price', 'terms', 'trust', 'after'];

    private function tenant(TenantContext $ctx): array
    {
        return ['organization_id' => $ctx->organizationId, 'brand_id' => $ctx->brandId];
    }

    public function read(#[\SensitiveParameter] Request $request): array
    {
        return $this->readAccess($request, function (TenantContext $ctx, User $user): array {
            $where = $this->tenant($ctx);
            $editable = Gate::forUser($user)->allows('vision.quiz.write');
            $profile = $editable ? DB::table('brand_profiles')->where($where)->orderByDesc('version')->first(['id', 'quiz_id']) : null;
            $candidates = $profile ? DB::table('question_candidates as c')
                ->join('question_generation_batches as b', 'b.id', '=', 'c.batch_id')
                ->leftJoin('vision_question_manual_batches as m', 'm.batch_id', '=', 'c.batch_id')
                ->where('c.organization_id', $ctx->organizationId)->where('c.brand_id', $ctx->brandId)->where('c.profile_id', $profile->id)
                ->where(fn ($q) => $q->where('b.status', 'completed')->orWhereNotNull('m.batch_id'))
                ->orderBy('c.created_at')->orderBy('c.id')->get(['c.id', 'c.text', 'c.intent', 'c.status', 'c.selected', 'm.created_by as manual_author', 'm.origin as manual_origin'])
                ->map(fn ($row) => ['id' => $row->id, 'text' => $row->text, 'intent' => $row->intent, 'status' => $row->status, 'selected' => (bool) $row->selected,
                    'origin' => $row->manual_author ? $row->manual_origin : 'suggested', 'can_delete' => $row->manual_origin === 'manual' && $row->manual_author === $user->id])->all() : [];
            $approvals = DB::table('vision_question_approvals as a')->join('question_sets as s', 's.id', '=', 'a.question_set_id')->join('users as u', 'u.id', '=', 'a.approved_by')
                ->where('a.organization_id', $ctx->organizationId)->where('a.brand_id', $ctx->brandId)->orderByDesc('s.version')
                ->limit(100)->get(['s.id', 's.version', 's.questions', 's.size', 's.checksum', 'a.providers', 'a.frequency', 'a.active', 'a.created_at', 'a.approved_by', 'u.name as approver_name']);
            $measurements = DB::table('measurements')->where('organization_id', $ctx->organizationId)->where('brand_id', $ctx->brandId)
                ->orderBy('created_at')->orderBy('id')->get(['id', 'question_set_id', 'status', 'created_at'])
                ->map(function ($measurement, $index) {
                    $measurement->number = $index + 1;

                    return $measurement;
                })->groupBy('question_set_id');
            $history = $approvals->map(function ($row) use ($measurements): array {
                $questions = json_decode($row->questions, true, 32, JSON_THROW_ON_ERROR);
                $providers = json_decode($row->providers, true, 32, JSON_THROW_ON_ERROR);
                if (! is_array($questions) || ! is_array($providers) || ! hash_equals($row->checksum, CanonicalJson::hash($questions))) {
                    throw new LogicException('Question set is unavailable.');
                }

                return ['id' => $row->id, 'version' => (int) $row->version, 'questions' => $questions, 'size' => (int) $row->size,
                    'checksum' => $row->checksum, 'providers' => $providers, 'frequency' => $row->frequency, 'active' => (bool) $row->active,
                    'approved_by' => $row->approved_by, 'approver_name' => $row->approver_name, 'created_at' => $row->created_at,
                    'measurements' => ($measurements[$row->id] ?? collect())->map(fn ($measurement) => ['id' => $measurement->id, 'number' => $measurement->number, 'status' => $measurement->status, 'created_at' => $measurement->created_at])->values()->all()];
            })->all();
            $limits = app(PlanLimits::class);
            $catalog = app(ProviderCatalog::class)->models();

            return ['candidates' => $candidates, 'active_set' => collect($history)->firstWhere('active', true), 'history' => $history,
                'limits' => ['questions' => $limits->get($ctx, 'vision.questions')->value, 'models' => $limits->get($ctx, 'vision.models')->value],
                'models' => collect($catalog)->map(fn ($item, $id) => ['id' => $id, 'provider' => $item['provider'], 'status' => $item['status']])->values()->all(),
                'generation' => ['status' => 'blocked'], 'can_edit' => $editable, 'has_profile' => $profile !== null];
        });
    }

    public function add(#[\SensitiveParameter] Request $request, #[\SensitiveParameter] array $data): string
    {
        $clean = $this->validate($data);

        return app(ProfileAccess::class)->run($request, function (TenantContext $ctx, User $user) use ($request, $clean): string {
            $where = $this->tenant($ctx);
            $profile = DB::table('brand_profiles')->where($where)->orderByDesc('version')->first(['id', 'quiz_id']);
            if (! $profile) {
                throw new InvalidArgumentException('Profile required.');
            }
            $this->assertNoBrand($request, $profile->id, $clean['text']);
            $batch = DB::table('vision_question_manual_batches')->where($where)->where('profile_id', $profile->id)->where('created_by', $user->id)->where('origin', 'manual')->first();
            if (! $batch) {
                $batchId = (string) Str::ulid();
                DB::table('question_generation_batches')->insert(['id' => $batchId, ...$where, 'quiz_id' => $profile->quiz_id, 'profile_id' => $profile->id, 'status' => 'blocked', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('vision_question_manual_batches')->insert(['batch_id' => $batchId, ...$where, 'quiz_id' => $profile->quiz_id, 'profile_id' => $profile->id, 'created_by' => $user->id, 'origin' => 'manual', 'created_at' => now()]);
            } else {
                $batchId = $batch->batch_id;
            }
            $id = (string) Str::ulid();
            DB::table('question_candidates')->insert(['id' => $id, ...$where, 'quiz_id' => $profile->quiz_id, 'profile_id' => $profile->id, 'batch_id' => $batchId,
                ...$clean, 'status' => 'proposed', 'selected' => false, 'created_at' => now(), 'updated_at' => now()]);
            app(AuditWriter::class)->record($ctx, new AuditEntry('vision.question.created', 'question_candidate', $id));

            return $id;
        });
    }

    /** Explicit import of self-declared questions; no inference or external generation. */
    public function importProfile(#[\SensitiveParameter] Request $request, string $intent): array
    {
        if (! in_array($intent, self::INTENTS, true)) {
            throw new InvalidArgumentException('Invalid intent.');
        }

        return app(ProfileAccess::class)->run($request, function (TenantContext $ctx, User $user) use ($request, $intent): array {
            $where = $this->tenant($ctx);
            $profile = DB::table('brand_profiles')->where($where)->orderByDesc('version')->first(['id', 'quiz_id']);
            if (! $profile) {
                throw new InvalidArgumentException('Profile required.');
            }
            $answers = app(Questionnaire::class)->profile($request, $profile->id)['answers'];
            $questions = $answers['step7']['questions'] ?? [];
            if (! is_array($questions) || ! array_is_list($questions) || count($questions) > 15) {
                throw new InvalidArgumentException('Invalid questionnaire.');
            }
            $batch = DB::table('vision_question_manual_batches')->where($where)->where('profile_id', $profile->id)->where('created_by', $user->id)->where('origin', 'questionnaire')->first();
            if (! $batch) {
                $batchId = (string) Str::ulid();
                DB::table('question_generation_batches')->insert(['id' => $batchId, ...$where, 'quiz_id' => $profile->quiz_id, 'profile_id' => $profile->id, 'status' => 'blocked', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('vision_question_manual_batches')->insert(['batch_id' => $batchId, ...$where, 'quiz_id' => $profile->quiz_id, 'profile_id' => $profile->id, 'created_by' => $user->id, 'origin' => 'questionnaire', 'created_at' => now()]);
            } else {
                $batchId = $batch->batch_id;
            }
            $added = 0;
            $skipped = 0;
            foreach ($questions as $text) {
                try {
                    $clean = $this->validate(['text' => $text, 'intent' => $intent]);
                    $this->assertNoBrand($request, $profile->id, $clean['text']);
                } catch (InvalidArgumentException) {
                    $skipped++;

                    continue;
                }
                if (DB::table('question_candidates')->where($where)->where('batch_id', $batchId)->where('text', $clean['text'])->exists()) {
                    $skipped++;

                    continue;
                }
                DB::table('question_candidates')->insert(['id' => (string) Str::ulid(), ...$where, 'quiz_id' => $profile->quiz_id, 'profile_id' => $profile->id, 'batch_id' => $batchId,
                    ...$clean, 'status' => 'proposed', 'selected' => false, 'created_at' => now(), 'updated_at' => now()]);
                $added++;
            }
            app(AuditWriter::class)->record($ctx, new AuditEntry('vision.questions.imported_from_profile', 'brand_profile', $profile->id, after: ['added' => $added, 'skipped' => $skipped]));

            return ['added' => $added, 'skipped' => $skipped];
        });
    }

    /** Create a new draft from the current frozen set without modifying its snapshot. */
    public function copyActive(#[\SensitiveParameter] Request $request): array
    {
        return app(ProfileAccess::class)->run($request, function (TenantContext $ctx, User $user) use ($request): array {
            $where = $this->tenant($ctx);
            $profile = DB::table('brand_profiles')->where($where)->orderByDesc('version')->first(['id', 'quiz_id']);
            $active = DB::table('vision_question_approvals as a')->join('question_sets as s', 's.id', '=', 'a.question_set_id')
                ->where('a.organization_id', $ctx->organizationId)->where('a.brand_id', $ctx->brandId)->where('a.active', true)
                ->first(['s.id', 's.questions', 's.checksum']);
            if (! $profile || ! $active) {
                throw new InvalidArgumentException('Active set or profile unavailable.');
            }
            $questions = json_decode($active->questions, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($questions) || ! array_is_list($questions) || count($questions) > 100
                || ! hash_equals($active->checksum, CanonicalJson::hash($questions))) {
                throw new LogicException('Question set unavailable.');
            }
            $batch = DB::table('vision_question_manual_batches')->where($where)->where('profile_id', $profile->id)->where('created_by', $user->id)
                ->where('origin', 'active_set')->where('source_set_id', $active->id)->first();
            $batchId = $batch?->batch_id;

            $added = 0;
            $skipped = 0;
            foreach ($questions as $question) {
                if (! is_array($question) || ! is_string($question['id'] ?? null) || mb_strlen($question['id']) > 100) {
                    throw new LogicException('Question set unavailable.');
                }
                // Preserve the existing candidate and the user's current selection/edits.
                if (DB::table('question_candidates')->where($where)->where('profile_id', $profile->id)->where('id', $question['id'])->exists()) {
                    $skipped++;

                    continue;
                }                try {
                    $clean = $this->validate(['text' => $question['text'] ?? null, 'intent' => $question['intent'] ?? null]);
                    $this->assertNoBrand($request, $profile->id, $clean['text']);
                } catch (InvalidArgumentException) {
                    $skipped++;

                    continue;
                }
                $existing = DB::table('vision_question_copy_entries as e')->join('question_candidates as c', 'c.id', '=', 'e.candidate_id')
                    ->where('e.organization_id', $ctx->organizationId)->where('e.brand_id', $ctx->brandId)->where('e.source_set_id', $active->id)
                    ->where('e.source_question_id', $question['id'])->where('c.profile_id', $profile->id)->exists();
                if ($existing) {
                    $skipped++;

                    continue;
                }
                if (! $batchId) {
                    $batchId = (string) Str::ulid();
                    DB::table('question_generation_batches')->insert(['id' => $batchId, ...$where, 'quiz_id' => $profile->quiz_id, 'profile_id' => $profile->id, 'status' => 'blocked', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
                    DB::table('vision_question_manual_batches')->insert(['batch_id' => $batchId, ...$where, 'quiz_id' => $profile->quiz_id, 'profile_id' => $profile->id, 'created_by' => $user->id, 'origin' => 'active_set', 'source_set_id' => $active->id, 'created_at' => now()]);
                }                $id = (string) Str::ulid();
                DB::table('question_candidates')->insert(['id' => $id, ...$where, 'quiz_id' => $profile->quiz_id, 'profile_id' => $profile->id, 'batch_id' => $batchId,
                    ...$clean, 'status' => 'proposed', 'selected' => true, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('vision_question_copy_entries')->insert(['candidate_id' => $id, ...$where, 'source_set_id' => $active->id, 'source_question_id' => $question['id']]);
                $added++;
            }
            app(AuditWriter::class)->record($ctx, new AuditEntry('vision.questions.copied_active', 'question_set', $active->id, after: ['added' => $added, 'skipped' => $skipped]));

            return ['added' => $added, 'skipped' => $skipped];
        });
    }

    public function change(#[\SensitiveParameter] Request $request, string $id, #[\SensitiveParameter] array $data, bool $delete = false): void
    {
        if (! Str::isUlid($id)) {
            throw new InvalidArgumentException('Invalid candidate.');
        }
        app(ProfileAccess::class)->run($request, function (TenantContext $ctx, User $user) use ($request, $id, $data, $delete): void {
            $where = $this->tenant($ctx);
            $row = DB::table('question_candidates as c')->leftJoin('vision_question_manual_batches as m', 'm.batch_id', '=', 'c.batch_id')
                ->where('c.id', $id)->where('c.organization_id', $ctx->organizationId)->where('c.brand_id', $ctx->brandId)->first(['c.*', 'm.created_by as manual_author', 'm.origin as manual_origin']);
            $current = DB::table('brand_profiles')->where($where)->orderByDesc('version')->value('id');
            if (! $row || $row->profile_id !== $current) {
                throw new LogicException('Candidate unavailable.');
            }
            if ($delete) {
                if ($row->manual_origin !== 'manual' || $row->manual_author !== $user->id) {
                    throw new LogicException('Candidate unavailable.');
                }
                DB::table('question_candidates')->where($where)->where('id', $id)->delete();
            } else {
                $updates = [];
                if (array_key_exists('text', $data) || array_key_exists('intent', $data)) {
                    $updates = $this->validate(['text' => $data['text'] ?? $row->text, 'intent' => $data['intent'] ?? $row->intent]);
                    $this->assertNoBrand($request, $current, $updates['text']);
                }
                if (array_key_exists('selected', $data)) {
                    if (! is_bool($data['selected'])) {
                        throw new InvalidArgumentException('Invalid selection.');
                    } $updates['selected'] = $data['selected'];
                }
                if (array_key_exists('status', $data)) {
                    if (! in_array($data['status'], ['proposed', 'disabled'], true)) {
                        throw new InvalidArgumentException('Invalid status.');
                    } $updates['status'] = $data['status'];
                    if ($data['status'] === 'disabled') {
                        $updates['selected'] = false;
                    }
                }
                if (($updates['selected'] ?? false) && ($updates['status'] ?? $row->status) === 'disabled') {
                    throw new InvalidArgumentException('Disabled candidate cannot be selected.');
                }
                if ($updates === [] || array_diff(array_keys($data), ['text', 'intent', 'selected', 'status'])) {
                    throw new InvalidArgumentException('Invalid input.');
                }
                DB::table('question_candidates')->where($where)->where('id', $id)->update([...$updates, 'updated_at' => now()]);
            }
            app(AuditWriter::class)->record($ctx, new AuditEntry($delete ? 'vision.question.deleted' : 'vision.question.updated', 'question_candidate', $id));
        });
    }

    public function approve(#[\SensitiveParameter] Request $request, array $ids, array $providers, ?string $frequency): string
    {
        if (! array_is_list($ids) || count($ids) < 1 || count($ids) > 100 || array_filter($ids, fn ($id) => ! is_string($id) || ! Str::isUlid($id))
            || count(array_unique($ids)) !== count($ids) || $providers === [] || array_is_list($providers)
            || ($frequency !== null && ! in_array($frequency, ['weekly', 'biweekly', 'monthly'], true))) {
            throw new InvalidArgumentException('Invalid approval.');
        }

        return app(ProfileAccess::class)->run($request, function (TenantContext $ctx, User $user) use ($request, $ids, $providers, $frequency): string {
            $where = $this->tenant($ctx);
            $profile = DB::table('brand_profiles')->where($where)->orderByDesc('version')->first(['id']);
            if (! $profile) {
                throw new InvalidArgumentException('Profile required.');
            }
            $assignments = DB::table('organization_plan')->where('organization_id', $ctx->organizationId)->sharedLock()->get(['plan_id']);
            $plans = $assignments->pluck('plan_id')->unique()->sort()->values()->all();
            if ($plans) {
                DB::table('plans')->whereIn('id', $plans)->orderBy('id')->lockForUpdate()->get(['id']);
                DB::table('plan_limits')->whereIn('plan_id', $plans)->whereIn('key', ['vision.questions', 'vision.models'])->sharedLock()->get(['id']);
            }
            app(LimitEnforcer::class)->assertWithin($ctx, 'vision.questions', (string) count($ids));
            app(LimitEnforcer::class)->assertWithin($ctx, 'vision.models', (string) count($providers));
            DB::table('ai_providers')->whereIn('code', array_keys($providers))->orderBy('code')->sharedLock()->get(['code']);
            $catalog = app(ProviderCatalog::class)->models();
            foreach ($providers as $provider => $model) {
                if (! is_string($provider) || ! is_string($model) || ($catalog[$model]['status'] ?? null) !== 'active' || ($catalog[$model]['provider'] ?? null) !== $provider) {
                    throw new InvalidArgumentException('Model unavailable.');
                }
            }
            // Stabilize batch statuses before locking candidate rows, in deterministic order.
            $batchIds = DB::table('question_candidates')->where($where)->where('profile_id', $profile->id)
                ->whereIn('id', $ids)->pluck('batch_id')->unique()->sort()->values()->all();
            DB::table('question_generation_batches')->where($where)->whereIn('id', $batchIds)
                ->orderBy('id')->sharedLock()->get(['id']);
            $rows = DB::table('question_candidates as c')
                ->join('question_generation_batches as b', 'b.id', '=', 'c.batch_id')
                ->leftJoin('vision_question_manual_batches as m', 'm.batch_id', '=', 'c.batch_id')
                ->where('c.organization_id', $ctx->organizationId)->where('c.brand_id', $ctx->brandId)
                ->where('c.profile_id', $profile->id)->whereIn('c.id', $ids)->where('c.status', '<>', 'disabled')
                ->where(fn ($query) => $query->where('b.status', 'completed')->orWhereNotNull('m.batch_id'))
                ->orderBy('c.id')->lock('for update of c')->get(['c.*'])->keyBy('id');
            if ($rows->count() !== count($ids)) {
                throw new InvalidArgumentException('Candidate unavailable.');
            }
            $questions = [];
            foreach ($ids as $id) {
                $row = $rows[$id];
                $this->validate(['text' => $row->text, 'intent' => $row->intent]);
                $this->assertNoBrand($request, $profile->id, $row->text);
                $questions[] = ['id' => $id, 'text' => $row->text, 'intent' => $row->intent];
            }
            $setId = app(QuestionSets::class)->create($questions);
            DB::table('vision_question_approvals')->where($where)->where('active', true)->update(['active' => false]);
            DB::table('vision_question_approvals')->insert(['id' => (string) Str::ulid(), ...$where, 'question_set_id' => $setId, 'approved_by' => $user->id,
                'providers' => json_encode($providers, JSON_THROW_ON_ERROR), 'frequency' => $frequency, 'active' => true, 'created_at' => now()]);
            DB::table('question_candidates')->where($where)->where('profile_id', $profile->id)->update(['status' => DB::raw("case when status = 'active' then 'proposed' else status end"), 'selected' => false, 'updated_at' => now()]);
            DB::table('question_candidates')->where($where)->where('profile_id', $profile->id)->whereIn('id', $ids)->update(['status' => 'active', 'selected' => true, 'updated_at' => now()]);
            app(AuditWriter::class)->record($ctx, new AuditEntry('vision.questions.approved', 'question_set', $setId, after: ['count' => count($questions)]));

            return $setId;
        });
    }

    private function assertNoBrand(#[\SensitiveParameter] Request $request, string $profileId, #[\SensitiveParameter] string $text): void
    {
        $text = VisibilityProfile::text($text);
        $profile = app(SelfDeclaredProfile::class)->read($request, $profileId);
        $answers = app(Questionnaire::class)->profile($request, $profileId)['answers'];
        $first = is_array($answers['step1'] ?? null) ? $answers['step1'] : [];
        $second = is_array($answers['step2'] ?? null) ? $answers['step2'] : [];
        $former = is_array($second['former_names'] ?? null) ? $second['former_names'] : [];
        $oldNames = is_array($second['old_names'] ?? null) ? $second['old_names'] : [];
        $domain = (string) ($profile['brand_domain'] ?? '');
        $stem = explode('.', $domain)[0] ?? '';
        $aliases = array_merge(VisibilityProfile::recognitionAliases($profile), [$first['legal_name'] ?? '', $stem], $former, $oldNames);
        foreach (array_filter($aliases, fn ($value): bool => is_string($value) && trim($value) !== '') as $alias) {
            $alias = VisibilityProfile::text($alias);
            if (! VisibilityProfile::isAmbiguous($alias, $profile)) {
                if (preg_match('~(?<![\p{L}\p{N}])'.preg_quote($alias, '~').'(?![\p{L}\p{N}])~iu', $text)) {
                    throw new InvalidArgumentException('Question must not contain a brand name.');
                }

                continue;
            }
            $stem = preg_replace('/(?:ами|ями|ов|ев|ах|ях|ам|ям|ом|ем|ы|и|а|я|у|ю|е|о)$/u', '', mb_strtolower($alias));
            if (! is_string($stem) || $stem === '') {
                continue;
            }
            $word = preg_quote($stem, '~').'[\p{L}]*';
            if (preg_match('~[«"„“]\s*'.$word.'\s*[»"“”]|(?:компания|фирма|бренд|магазин|кондитерская)\s+[«"„“]?'.$word.'(?!\p{L})~iu', $text)) {
                throw new InvalidArgumentException('Question must not contain a brand name.');
            }
            if (preg_match_all('~(?<!\p{L})('.$word.')(?!\p{L})~iu', $text, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[1] as [$match, $offset]) {
                    if ($offset > 0 && preg_match('/^\p{Lu}/u', $match) && trim(substr($text, 0, $offset)) !== '') {
                        throw new InvalidArgumentException('Question must not contain a brand name.');
                    }
                }
            }
        }
    }

    private function validate(#[\SensitiveParameter] array $data): array
    {
        $text = $data['text'] ?? null;
        $intent = $data['intent'] ?? null;
        if (array_diff(array_keys($data), ['text', 'intent']) || ! is_string($text) || ! mb_check_encoding($text, 'UTF-8') || mb_strlen(trim($text)) < 15 || mb_strlen($text) > 220
            || preg_match('/[{}\[\]]/u', $text) || ! is_string($intent) || ! in_array($intent, self::INTENTS, true)) {
            throw new InvalidArgumentException('Invalid question.');
        }

        return ['text' => trim(preg_replace('/\s+/u', ' ', $text)), 'intent' => $intent];
    }

    private function readAccess(#[\SensitiveParameter] Request $request, Closure $callback): mixed
    {
        return app(AuthSessionRegistry::class)->withinActive($request, function (User $user) use ($request, $callback) {
            $ctx = app(TenantContext::class);
            if ($ctx->source !== 'session' || $ctx->actorId !== $user->id || ! $ctx->organizationId || ! $ctx->brandId || ! $ctx->membershipId
                || $request->session()->get('brand_id') !== $ctx->brandId || ($request->hasHeader('X-Brand-Id') && $request->header('X-Brand-Id') !== $ctx->brandId)) {
                throw new LogicException('Access denied.');
            }
            $org = DB::table('organizations')->where('id', $ctx->organizationId)->where('status', 'active')->sharedLock()->first();
            $brand = DB::table('brands')->where('id', $ctx->brandId)->where('organization_id', $ctx->organizationId)->where('status', 'active')->sharedLock()->first();
            $member = Membership::withoutGlobalScopes()->whereKey($ctx->membershipId)->where('organization_id', $ctx->organizationId)->where('user_id', $user->id)->where('status', 'active')->sharedLock()->first();
            if (! $org || ! $brand || ! $member || ! $member->permitsBrand($ctx->brandId) || ! app(ModuleEntitlements::class)->enabled($ctx, 'vision') || ! Gate::forUser($user)->allows('reports.read')) {
                throw new LogicException('Access denied.');
            }

            return $callback($ctx, $user);
        });
    }
}
