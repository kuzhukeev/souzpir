<?php

namespace App\Vision\Clarifications;

use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use App\Vision\Core\Profiles\Questionnaire;
use App\Vision\Measurements\MeasurementList;
use App\Vision\Profile\CompletionRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class ClarificationController
{
    private const DECISIONS = [
        'mentions' => ['yes', 'no', 'unknown', 'deferred'],
        'presence' => ['yes', 'no', 'unknown', 'deferred'],
        'facts' => ['correct', 'incorrect', 'outdated', 'deferred'],
    ];

    public function index(Request $request): mixed
    {
        return app(MeasurementList::class)->withinCurrent($request, function (TenantContext $tenant): mixed {
            $items = DB::table('snapshot_confirmations')->where('organization_id', $tenant->organizationId)
                ->where('brand_id', $tenant->brandId)->orderByDesc('created_at')->limit(50)
                ->get(['measurement_id', 'created_at']);

            return Inertia::render('Vision/Clarifications/Index', ['measurements' => $items]);
        }, false);
    }

    public function show(Request $request, string $measurement, string $step): mixed
    {
        return app(MeasurementList::class)->withinCurrent($request, function (TenantContext $tenant) use ($measurement, $step): mixed {
            $confirmation = $this->confirmation($tenant, $measurement);
            $catalog = $confirmation ? $this->catalog($tenant, $confirmation->id) : ['mentions' => [], 'presence' => [], 'facts' => []];
            if ($step === 'presence') {
                $platforms = $this->questionnairePlatforms($tenant);
                $catalog['presence'] = array_map(function (array $item) use ($platforms): array {
                    $url = $this->questionnairePresence($platforms, $item['value']);

                    return [...$item, 'from_questionnaire' => $url !== null, 'questionnaire_url' => $url];
                }, $catalog['presence']);
            }
            $saved = $confirmation ? DB::table('vision_clarification_decisions')->where('organization_id', $tenant->organizationId)
                ->where('brand_id', $tenant->brandId)->where('snapshot_confirmation_id', $confirmation->id)
                ->get(['item_key', 'decision', 'correction', 'profile_url', 'add_alias'])->keyBy('item_key') : collect();
            $items = [];
            $pending = 0;
            $total = 0;
            foreach ($catalog as $kind => $rows) {
                foreach ($rows as $row) {
                    $decision = $saved[$row['key']] ?? null;
                    if (! $decision || in_array($decision->decision, ['unknown', 'deferred'], true)) {
                        $pending++;
                    }
                    $total++;
                    if ($kind === $step) {
                        $items[] = [...$row, 'decision' => $decision?->decision, 'correction' => $decision?->correction,
                            'profile_url' => $decision?->profile_url, 'add_alias' => $decision?->add_alias ?? false];
                    }
                }
            }

            return Inertia::render('Vision/Clarifications/Wizard', ['measurement' => $measurement, 'step' => $step,
                'available' => (bool) $confirmation, 'items' => $items, 'total' => $total, 'pending' => $pending,
                'status' => session('status'), 'alias_available' => Gate::allows('vision.quiz.write') && DB::table('brand_profiles')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->exists()]);
        }, false);
    }

    public function save(Request $request, string $measurement, string $step): mixed
    {
        $input = $request->validate(['item_key' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'decision' => ['required', Rule::in(self::DECISIONS[$step])], 'correction' => ['nullable', 'string', 'max:1000'],
            'profile_url' => ['nullable', 'url', 'max:1000'], 'add_alias' => ['sometimes', 'boolean'], 'apply_alias' => ['sometimes', 'boolean']]);
        app(MeasurementList::class)->withinCurrent($request, function (TenantContext $tenant) use ($measurement, $step, $input, $request): void {
            Gate::authorize('vision.measure.run');
            $confirmation = $this->confirmation($tenant, $measurement);
            if (! $confirmation) {
                throw ValidationException::withMessages(['measurement' => 'Сначала подтвердите завершённый замер.']);
            }
            $item = collect($this->catalog($tenant, $confirmation->id)[$step])->firstWhere('key', $input['item_key']);
            if (! $item) {
                throw ValidationException::withMessages(['item_key' => 'Уточнение недоступно. Обновите страницу.']);
            }
            if ($step === 'facts' && in_array($input['decision'], ['incorrect', 'outdated'], true) && trim($input['correction'] ?? '') === '') {
                throw ValidationException::withMessages(['correction' => 'Укажите, как правильно.']);
            }
            if ($step !== 'facts' && ! empty($input['correction'])) {
                throw ValidationException::withMessages(['correction' => 'Исправление доступно только для фактов.']);
            }
            if ($step !== 'presence' && ! empty($input['profile_url'])) {
                throw ValidationException::withMessages(['profile_url' => 'Ссылка доступна только для площадок.']);
            }
            if ($step !== 'mentions' && ! empty($input['add_alias'])) {
                throw ValidationException::withMessages(['add_alias' => 'Вариант названия доступен только для упоминаний.']);
            }
            if ($input['apply_alias'] ?? false) {
                Gate::authorize('vision.quiz.write');
                if ($step !== 'mentions' || $input['decision'] !== 'yes') {
                    throw ValidationException::withMessages(['apply_alias' => 'Вариант названия доступен только для подтверждённого упоминания.']);
                }
                $profileId = DB::table('brand_profiles')->where('organization_id', $tenant->organizationId)
                    ->where('brand_id', $tenant->brandId)->orderByDesc('version')->value('id');
                if (! $profileId) {
                    throw ValidationException::withMessages(['apply_alias' => 'Сначала заполните профиль бренда.']);
                }
                try {
                    $questionnaire = app(Questionnaire::class);
                    $profile = $questionnaire->profile($request, $profileId);
                    $answers = $profile['answers'];
                    $aliases = $answers['step2']['aliases'] ?? [];
                    if (! is_array($aliases)) {
                        throw new \LogicException;
                    }
                    if (! in_array($item['value'], $aliases, true)) {
                        $answers['step2']['aliases'][] = $item['value'];
                        app(CompletionRules::class)->validate($answers);
                        $questionnaire->edit($request, $profileId, 2, $answers['step2']);
                    }
                } catch (\Throwable) {
                    throw ValidationException::withMessages(['apply_alias' => 'Не удалось добавить вариант. Проверьте профиль бренда.']);
                }
            }
            $existing = DB::table('vision_clarification_decisions')->where('snapshot_confirmation_id', $confirmation->id)
                ->where('item_key', $item['key'])->first(['id']);
            $values = ['decision' => $input['decision'], 'correction' => $step === 'facts' ? ($input['correction'] ?? null) : null,
                'profile_url' => $step === 'presence' ? ($input['profile_url'] ?? null) : null,
                'add_alias' => $step === 'mentions' && $input['decision'] === 'yes' && (($input['add_alias'] ?? false) || ($input['apply_alias'] ?? false)),
                'decided_by' => $tenant->actorId, 'updated_at' => now()];
            if ($existing) {
                DB::table('vision_clarification_decisions')->where('id', $existing->id)->update($values);
            } else {
                DB::table('vision_clarification_decisions')->insert([...$values, 'id' => (string) Str::ulid(),
                    'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
                    'snapshot_confirmation_id' => $confirmation->id, 'answer_fact_id' => $item['answer_fact_id'],
                    'item_key' => $item['key'], 'kind' => $step, 'created_at' => now()]);
            }
            app(AuditWriter::class)->record($tenant, new AuditEntry('vision.clarification.saved', 'snapshot_confirmation', $confirmation->id,
                after: ['kind' => $step, 'decision' => $input['decision']]));
        }, false);

        return back()->with('status', 'Ответ сохранён.');
    }

    public function pendingCount(TenantContext $tenant, string $measurement): int
    {
        $confirmation = $this->confirmation($tenant, $measurement);
        if (! $confirmation) {
            return 0;
        }
        $decisions = DB::table('vision_clarification_decisions')->where('organization_id', $tenant->organizationId)
            ->where('brand_id', $tenant->brandId)->where('snapshot_confirmation_id', $confirmation->id)
            ->pluck('decision', 'item_key');
        $pending = 0;
        foreach ($this->catalog($tenant, $confirmation->id) as $items) {
            foreach ($items as $item) {
                $decision = $decisions[$item['key']] ?? null;
                if ($decision === null || in_array($decision, ['unknown', 'deferred'], true)) {
                    $pending++;
                }
            }
        }

        return $pending;
    }

    private function confirmation(TenantContext $tenant, string $measurement): ?object
    {
        $row = DB::table('measurements')->where('organization_id', $tenant->organizationId)
            ->where('brand_id', $tenant->brandId)->where('id', $measurement)->first(['id', 'status']);
        abort_unless($row, 404);

        return DB::table('snapshot_confirmations')->where('organization_id', $tenant->organizationId)
            ->where('brand_id', $tenant->brandId)->where('measurement_id', $measurement)->first(['id']);
    }

    private function catalog(TenantContext $tenant, string $confirmation): array
    {
        $catalog = ['mentions' => [], 'presence' => [], 'facts' => []];
        $rows = DB::table('answer_facts')->where('organization_id', $tenant->organizationId)
            ->where('brand_id', $tenant->brandId)->where('snapshot_confirmation_id', $confirmation)
            ->orderBy('id')->get(['id', 'fact', 'answer_text']);
        foreach ($rows as $row) {
            $fact = json_decode($row->fact ?? '{}', true) ?: [];
            if (is_string($fact['value'] ?? null) && trim($fact['value']) !== '') {
                $catalog['facts'][] = $this->item('facts', $row->id, $fact['value'], $row->answer_text);
            }
            foreach (['mentions' => 'identity_candidates', 'presence' => 'presence_candidates'] as $kind => $field) {
                foreach (array_slice(is_array($fact[$field] ?? null) ? $fact[$field] : [], 0, 20) as $candidate) {
                    $value = is_string($candidate) ? $candidate : ($candidate['name'] ?? $candidate['domain'] ?? null);
                    if (is_string($value) && trim($value) !== '') {
                        $catalog[$kind][] = $this->item($kind, $row->id, $value, $row->answer_text);
                    }
                }
            }
        }

        return $catalog;
    }

    private function item(string $kind, string $factId, string $value, ?string $excerpt): array
    {
        return ['key' => hash('sha256', $kind.':'.$factId.':'.$value), 'answer_fact_id' => $factId,
            'value' => $value, 'excerpt' => $excerpt];
    }

    private function questionnairePlatforms(TenantContext $tenant): array
    {
        $stored = DB::table('brand_profiles')->where('organization_id', $tenant->organizationId)
            ->where('brand_id', $tenant->brandId)->orderByDesc('version')->value('answers');
        $answers = is_string($stored) ? json_decode($stored, true) : null;
        $platforms = data_get($answers, 'step6.presence', []);

        return is_array($platforms) ? $platforms : [];
    }

    private function questionnairePresence(array $platforms, string $candidate): ?string
    {
        $candidateHost = parse_url(str_contains($candidate, '://') ? $candidate : 'https://'.$candidate, PHP_URL_HOST);
        foreach ($platforms as $platform) {
            if (! is_array($platform) || ! is_string($platform['url'] ?? null) || ! in_array(parse_url($platform['url'], PHP_URL_SCHEME), ['http', 'https'], true)) {
                continue;
            }
            $url = $platform['url'];
            $host = parse_url($url, PHP_URL_HOST);
            if ((is_string($host) && is_string($candidateHost) && strcasecmp($host, $candidateHost) === 0)
                || (is_string($platform['name'] ?? null) && mb_strtolower($platform['name']) === mb_strtolower($candidate))) {
                return $url;
            }
        }

        return null;
    }
}
