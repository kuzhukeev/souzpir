<?php

namespace App\Vision\Measurements;

use App\Ai\CanonicalJson;
use App\Tenancy\TenantContext;
use App\Vision\Core\VisionReportReader;
use App\Vision\Evidence\Contracts\KnowledgeGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

final class AnswersController
{
    public function index(Request $request, string $measurement): mixed
    {
        return app(MeasurementList::class)->withinCurrent($request, function (TenantContext $tenant, string $revision) use ($measurement) {
            $this->measurement($tenant, $measurement);

            return Inertia::render('Vision/Measurements/Answers', ['measurement' => $measurement, 'measurementBrandRevision' => $revision]);
        }, false);
    }

    public function data(Request $request, string $measurement): mixed
    {
        return app(MeasurementList::class)->withinCurrent($request, function (TenantContext $tenant, string $revision) use ($measurement) {
            $plan = $this->measurement($tenant, $measurement);
            abort_unless(Gate::allows('vision.measure.full_access'), 403);
            $profile = json_decode($plan->profile_snapshot ?? '{}', true);
            abort_unless(is_array($profile) && is_string($plan->profile_checksum) && hash_equals($plan->profile_checksum, CanonicalJson::hash($profile)), 409);
            try {
                app(KnowledgeGate::class)->assertAllowed($tenant, $measurement, $profile);
            } catch (\Throwable) {
                abort(409, 'Ответы замера недоступны.');
            }
            $set = DB::table('question_sets')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('id', $plan->question_set_id)->first(['questions']) ?? abort(404);
            $questions = json_decode($set->questions, true) ?: [];
            $confirmation = DB::table('snapshot_confirmations')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('measurement_id', $measurement)->value('id');
            $report = $confirmation ? DB::table('vision_reports')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('measurement_id', $measurement)->orderByDesc('created_at')->orderByDesc('id')->value('id') : null;
            $slots = [];
            if ($report) {
                try {
                    $snapshot = app(VisionReportReader::class)->read($report);
                    $slots = collect(data_get($snapshot, 'metrics.slots', []))->keyBy('id')->all();
                } catch (\LogicException) {
                    abort(409, 'Снимок замера недоступен.');
                }
            }
            $facts = $confirmation ? DB::table('answer_facts')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('snapshot_confirmation_id', $confirmation)->get(['id', 'measurement_answer_id', 'fact'])->keyBy('measurement_answer_id') : collect();
            $decisions = $confirmation ? DB::table('vision_clarification_decisions')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('snapshot_confirmation_id', $confirmation)->where('kind', 'mentions')->pluck('decision', 'item_key')->all() : [];
            $answers = DB::table('measurement_answers')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('measurement_id', $measurement)->orderBy('question_n')->orderBy('provider')->orderBy('run_no')->get(['id', 'question_n', 'provider', 'requested_model', 'run_no', 'status', 'answer_text', 'error_code', 'created_at']);
            $items = $answers->map(function ($answer) use ($questions, $slots, $facts, $decisions): array {
                $question = $questions[$answer->question_n - 1] ?? null;
                $slot = $slots[$answer->id] ?? [];
                $fact = $facts->get($answer->id);
                $factData = $fact ? (json_decode($fact->fact ?? '{}', true) ?: []) : [];
                $candidates = collect(is_array($factData['identity_candidates'] ?? null) ? $factData['identity_candidates'] : [])->map(function ($candidate) use ($fact, $decisions): ?array {
                    $name = is_string($candidate) ? $candidate : ($candidate['name'] ?? null);

                    if (! is_string($name) || trim($name) === '') {
                        return null;
                    }
                    $key = hash('sha256', 'mentions:'.$fact->id.':'.$name);

                    return ['name' => $name, 'key' => $key, 'decision' => $decisions[$key] ?? null];
                })->filter()->values()->all();
                $verified = $slot['fact'] ?? null;

                return ['id' => $answer->id, 'question_n' => $answer->question_n, 'intent' => is_array($question) && is_string($question['intent'] ?? null) ? $question['intent'] : null, 'question' => is_array($question) ? ($question['text'] ?? '') : (is_string($question) ? $question : ''), 'provider' => $answer->provider, 'model' => $answer->requested_model, 'run_no' => $answer->run_no, 'status' => $answer->status, 'text' => $answer->answer_text, 'error' => $answer->error_code, 'created_at' => $answer->created_at, 'fact' => $verified, 'mentions' => $slot['mentions'] ?? [], 'sources' => $slot['sources'] ?? [], 'candidates' => $candidates];
            })->all();

            $number = DB::table('measurements')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->where(fn ($query) => $query->where('created_at', '<', $plan->created_at)
                    ->orWhere(fn ($same) => $same->where('created_at', $plan->created_at)->where('id', '<=', $plan->id)))->count();

            return response()->json(['revision' => $revision, 'measurement_number' => $number, 'measurement_date' => $plan->created_at, 'answers' => $items]);
        });
    }

    private function measurement(TenantContext $tenant, string $id): object
    {
        return DB::table('measurements')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('id', $id)->first(['id', 'question_set_id', 'profile_snapshot', 'profile_checksum', 'created_at']) ?? abort(404);
    }
}
