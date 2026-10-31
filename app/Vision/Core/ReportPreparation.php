<?php

namespace App\Vision\Core;

use App\Ai\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class ReportPreparation
{
    public const SOURCE_TYPES = ['maps' => 'Карты', 'marketplace' => 'Маркетплейс', 'rating' => 'Отзывы и рейтинги',
        'forum' => 'Форум', 'media' => 'СМИ и статьи', 'catalog' => 'Каталог', 'other' => 'Тип не определён'];

    public function __construct(private Tenant $tenant, private VisibilityMetrics $metrics) {}

    /** Persist a new immutable internal calculation; publication is a separate feature. */
    public function snapshot(string $measurementId): string
    {
        $tenant = $this->tenant->current();

        return DB::transaction(function () use ($measurementId, $tenant): string {
            $measurement = DB::table('measurements')->where($tenant)->where('id', $measurementId)->lockForUpdate()->first();
            if (! $measurement) {
                throw new LogicException('Measurement not found.');
            }
            if (! in_array($measurement->status, ['completed', 'partial'], true)) {
                throw new LogicException('Measurement must reach a terminal state before the snapshot.');
            }
            $configuration = json_decode($measurement->configuration, true, flags: JSON_THROW_ON_ERROR);
            $questions = $configuration['questions'] ?? null;
            $providers = $configuration['providers'] ?? null;
            $runs = $configuration['runs'] ?? null;
            if (! is_array($questions) || $questions === [] || ! is_array($providers) || $providers === []
                || ! is_int($runs) || $runs < 1 || ! hash_equals((string) ($configuration['question_set_checksum'] ?? ''), CanonicalJson::hash($questions))) {
                throw new LogicException('Frozen measurement plan is invalid.');
            }
            $rows = DB::table('measurement_answers')->where($tenant)->where('measurement_id', $measurementId)->orderBy('provider')->orderBy('question_n')->orderBy('run_no')->get();
            $slots = [];
            foreach ($rows as $row) {
                $question = $questions[$row->question_n - 1] ?? null;
                if (! is_array($question) || ! isset($providers[$row->provider])
                    || $providers[$row->provider] !== $row->requested_model || $row->run_no < 1 || $row->run_no > $runs) {
                    throw new LogicException('Measurement contains an unplanned slot.');
                }
                // Raw model output and any unsourced data are not verified facts.
                // A later Evidence integration must supply its independently
                // validated provenance; until then all successful answers remain unknown.
                $slots[] = [
                    'id' => $row->id, 'provider' => $row->provider, 'question_n' => $row->question_n,
                    'qid' => $question['id'], 'intent' => $question['intent'], 'run_no' => $row->run_no,
                    'status' => $row->status, 'protocol_valid' => $row->protocol_valid ?? false,
                    'fact' => null, 'mentions' => [], 'sources' => [],
                ];
            }
            $metrics = $this->metrics->calculate($slots, array_keys($providers), count($questions), $runs);
            $payload = ['measurement_id' => $measurementId, 'question_set_checksum' => $configuration['question_set_checksum'],
                'questions' => $questions, 'providers' => $providers, 'runs' => $runs,
                'metrics' => $metrics, 'recommendations' => ['items' => []], 'evidence_status' => 'not_available'];
            $revision = 1 + (int) DB::table('vision_reports')->where($tenant)->where('measurement_id', $measurementId)->max('revision');
            $id = (string) Str::ulid();
            DB::table('vision_reports')->insert(['id' => $id, ...$tenant, 'measurement_id' => $measurementId,
                'revision' => $revision, 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
                'checksum' => CanonicalJson::hash($payload), 'created_at' => now(), 'updated_at' => now()]);

            return $id;
        });
    }

    /**
     * Adapted from the source project's review-item preparation.
     * Arguments must come from a trusted persisted snapshot/evidence repository.
     */
    public function items(array $snapshot, array $sources): array
    {
        $items = [];
        foreach (data_get($snapshot, 'metrics.slots', []) as $slot) {
            if (! ($slot['valid'] ?? false)) {
                continue;
            }
            foreach ($slot['fact']['suspected_errors'] ?? [] as $index => $error) {
                $claim = is_array($error) ? (string) ($error['claim'] ?? '') : (string) $error;
                if ($claim === '') {
                    continue;
                }
                $key = 'fact:'.($slot['id'] ?? CanonicalJson::hash($slot)).':'.$index;
                $items[$key] = ['key' => $key, 'kind' => 'fact', 'title' => 'Проверить утверждение модели',
                    'text' => $claim, 'reason' => is_array($error) ? ($error['reason'] ?? '') : '',
                    'answer_id' => $slot['id'] ?? null, 'provider' => $slot['provider'] ?? '',
                    'profile_facts' => data_get($snapshot, 'profile.facts', [])];
            }
        }
        foreach (data_get($snapshot, 'recommendations.items', []) as $index => $action) {
            if (! is_array($action) || empty($action['title'])) {
                continue;
            }
            $key = 'action:'.$index;
            $items[$key] = ['key' => $key, 'kind' => 'action', 'title' => $action['title'],
                'text' => $action['why'] ?? '', 'action' => $action];
        }
        foreach (array_slice($sources, 0, 10) as $source) {
            if (($source['presence_status'] ?? null) === 'not_found') {
                $key = 'presence:'.hash('sha256', $source['domain']);
                $items[$key] = ['key' => $key, 'kind' => 'presence',
                    'title' => 'Не найдено на проверенной странице: '.$source['domain'],
                    'text' => 'Это не доказывает отсутствие компании на всей площадке.', 'source' => $source];
            }
            if (($source['type_origin'] ?? null) === 'llm') {
                $key = 'source_type:'.hash('sha256', $source['domain']);
                $items[$key] = ['key' => $key, 'kind' => 'source_type', 'title' => 'Тип площадки: '.$source['domain'],
                    'text' => $source['type'], 'source' => $source];
            }
        }
        ksort($items);

        return $items;
    }

    /** Prepare only reviewed facts, actions and explicitly verified source evidence. */
    public function report(array $snapshot, array $reviewItems, array $decisions, array $sources): array
    {
        $report = $snapshot;
        $report['reviewed_facts'] = [];
        $report['recommendations']['items'] = [];
        foreach ($reviewItems as $key => $item) {
            $decision = $decisions[$key]['decision'] ?? null;
            if (($item['kind'] ?? '') === 'fact' && $decision !== 'rejected') {
                $report['reviewed_facts'][] = array_merge($item, ['confirmed' => $decision === 'confirmed']);
            }
            if (($item['kind'] ?? '') === 'action' && $decision === 'confirmed') {
                $report['recommendations']['items'][] = $item['action'];
            }
        }
        $report['reviewed_sources'] = array_map(function (array $source) use ($decisions): array {
            if (($source['presence_status'] ?? '') === 'not_found') {
                $key = 'presence:'.hash('sha256', $source['domain']);
                $source['presence_status'] = ($decisions[$key]['decision'] ?? '') === 'confirmed' ? 'not_found' : 'unknown';
            }
            if (($source['type_origin'] ?? '') === 'llm' && ($decisions['source_type:'.hash('sha256', $source['domain'])]['decision'] ?? '') !== 'confirmed') {
                $source['type'] = 'other';
            }

            return $source;
        }, $sources);
        $report['publication_review'] = ['approved_actions' => count($report['recommendations']['items']), 'reviewed_items' => count($decisions)];

        return $report;
    }
}
