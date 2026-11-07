<?php

namespace App\Vision\Measurements;

use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Plans\Contracts\PlanLimits;
use App\Shell\Plans\MonthlyUnits;
use App\Tenancy\TenantContext;
use App\Vision\Core\EvidenceStore;
use App\Vision\Core\MeasurementRunner;
use App\Vision\Evidence\LiveGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class MeasurementsController
{
    public function index(Request $request): mixed
    {
        return app(MeasurementList::class)->withinCurrent($request,
            fn (TenantContext $context, string $revision) => Inertia::render('Vision/Measurements/Index', [
                'measurementBrandRevision' => $revision,
                'status' => session('status'),
            ]), false);
    }

    public function progress(Request $request, string $measurement): mixed
    {
        return app(MeasurementList::class)->withinCurrent($request, function (TenantContext $context, string $revision) use ($measurement) {
            abort_unless(DB::table('measurements')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)->where('id', $measurement)->exists(), 404);

            return Inertia::render('Vision/Measurements/Progress', ['measurement' => $measurement, 'measurementBrandRevision' => $revision]);
        }, false);
    }

    public function progressData(Request $request, string $measurement): mixed
    {
        return app(MeasurementList::class)->withinCurrent($request, function (TenantContext $context, string $revision) use ($measurement) {
            $data = app(MeasurementList::class)->readDetailed($context, 1, null, $measurement);
            abort_if(empty($data['data']), 404);

            $row = $data['data'][0];
            $events = DB::table('measurement_answers')->where('organization_id', $context->organizationId)
                ->where('brand_id', $context->brandId)->where('measurement_id', $measurement)
                ->whereNotNull('settled_at')->whereIn('status', ['ok', 'failed', 'uncertain'])
                ->orderBy('settled_at')->get(['status', 'settled_at']);
            $row['response_events'] = [];
            foreach (['first_answer' => ['ok'], 'first_error' => ['failed', 'uncertain']] as $kind => $statuses) {
                $first = $events->first(fn ($event) => in_array($event->status, $statuses, true));
                if ($first) {
                    $row['response_events'][] = ['kind' => $kind, 'at' => $first->settled_at];
                }
            }
            $row['answers'] = array_map(fn (array $answer) => array_intersect_key($answer, array_flip(['question_n', 'provider', 'requested_model', 'status'])), $row['answers']);
            $row = array_intersect_key($row, array_flip(['status', 'created_at', 'started_at', 'finished_at', 'estimated_minutes', 'pending_clarifications', 'answers', 'response_events']));

            return response()->json(['revision' => $revision, 'measurement' => $row]);
        });
    }

    public function data(Request $request): mixed
    {
        $page = filter_var($request->query('page', 1), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
        abort_if($page === false, 404);
        $detailed = $request->query('view') === 'details';
        $month = $request->query('month');
        abort_if($month !== null && (! is_string($month) || ! preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D', $month)), 422);

        return app(MeasurementList::class)->withinCurrent($request,
            fn (TenantContext $context, string $revision) => response()->json(['revision' => $revision,
                ...($detailed ? app(MeasurementList::class)->readDetailed($context, $page, $month) : app(MeasurementList::class)->read($context, $page))]));
    }

    public function revision(Request $request): mixed
    {
        return app(MeasurementList::class)->withinCurrent($request,
            fn (TenantContext $context, string $revision) => response()->json(['revision' => $revision]));
    }

    public function schedule(Request $request): mixed
    {
        return app(MeasurementList::class)->withinCurrent($request, function (TenantContext $context) use ($request): mixed {
            Gate::authorize('vision.measure.run');
            $data = $request->validate([
                'enabled' => ['required', 'boolean'],
                'frequency' => ['nullable', 'in:weekly,biweekly,monthly'],
                'weekday' => ['nullable', 'integer', 'between:1,7'],
                'local_time' => ['nullable', 'date_format:H:i'],
                'models' => ['nullable', 'array'],
                'models.*' => ['string', 'max:120'],
            ]);
            try {
                app(MeasurementSchedule::class)->save($context, $data);
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['schedule' => 'Проверьте частоту, день, время и модели расписания.']);
            }

            return redirect()->route('vision.measurements.index')->with('status', 'Расписание сохранено.');
        });
    }

    public function start(Request $request): mixed
    {
        return app(MeasurementList::class)->withinCurrent($request, function (TenantContext $context) use ($request): mixed {
            Gate::authorize('vision.measure.run');
            $data = $request->validate(['runs' => ['required', 'integer', 'between:1,3']]);
            $approval = DB::table('vision_question_approvals')->where('organization_id', $context->organizationId)
                ->where('brand_id', $context->brandId)->where('active', true)->first();
            if (! $approval) {
                throw ValidationException::withMessages(['runs' => 'Сначала утвердите набор вопросов для этого бренда.']);
            }
            $providers = json_decode($approval->providers, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($providers) || $providers === []) {
                throw ValidationException::withMessages(['runs' => 'Панель моделей недоступна.']);
            }
            $questionCount = DB::table('question_sets')->where('organization_id', $context->organizationId)
                ->where('brand_id', $context->brandId)->where('id', $approval->question_set_id)->value('size');
            $slotLimit = app(PlanLimits::class)->get($context, 'vision.slots_month')->value;
            if (is_numeric($questionCount) && is_numeric($slotLimit)) {
                $needed = (int) $questionCount * count($providers) * (int) $data['runs'];
                $available = max(0, (int) floor((float) $slotLimit) - app(MonthlyUnits::class)->usage($context)['slots']);
                if ($needed > $available) {
                    throw ValidationException::withMessages(['runs' => 'Не хватает слотов. Уменьшите число моделей или дождитесь обновления лимита.']);
                }
            }
            try {
                $id = app(MeasurementRunner::class)->start($approval->question_set_id, $providers, (int) $data['runs']);
            } catch (\InvalidArgumentException|\LogicException) {
                throw ValidationException::withMessages(['runs' => 'Не удалось подготовить замер. Проверьте вопросы и доступные модели.']);
            }
            $this->enqueue($context, $id);
            app(AuditWriter::class)->record($context, new AuditEntry('vision.measurement.started', 'measurement', $id, after: ['runs' => (int) $data['runs']]));

            return redirect()->route('vision.measurements.index')->with('status', 'Замер создан и ожидает выполнения.');
        });
    }

    public function next(Request $request, string $measurement): mixed
    {
        $answer = app(MeasurementList::class)->withinCurrent($request, function (TenantContext $context) use ($measurement): ?string {
            Gate::authorize('vision.measure.run');
            abort_unless(config('runtime.ai_driver', 'fake') === 'fake' || config('eyon.live_measurements_enabled', false), 403);
            $plan = DB::table('measurements')->where('organization_id', $context->organizationId)
                ->where('brand_id', $context->brandId)->where('id', $measurement)->first(['id']);
            abort_unless($plan, 404);
            $answer = DB::table('measurement_answers')->where('organization_id', $context->organizationId)
                ->where('brand_id', $context->brandId)->where('measurement_id', $measurement)
                ->where('status', 'pending')->orderBy('question_n')->orderBy('provider')->orderBy('run_no')->first(['id']);

            return $answer?->id;
        });
        if ($answer === null) {
            return redirect()->route('vision.measurements.index')->with('status', 'Ожидающих слотов нет.');
        }
        try {
            $status = app(MeasurementRunner::class)->measure($answer);
        } catch (\LogicException) {
            return redirect()->route('vision.measurements.index')->with('status', 'Слот не удалось выполнить. Проверьте его состояние.');
        }
        if ($status === 'ok' && LiveGate::evidenceEnabled()) {
            app(EvidenceStore::class)->evaluate($answer);
        }

        return redirect()->route('vision.measurements.index')->with('status', 'Слот обработан.');
    }

    public function repeat(Request $request, string $measurement): mixed
    {
        return app(MeasurementList::class)->withinCurrent($request, function (TenantContext $context) use ($measurement): mixed {
            Gate::authorize('vision.measure.run');
            $plan = DB::table('measurements')->where('organization_id', $context->organizationId)
                ->where('brand_id', $context->brandId)->where('id', $measurement)->first();
            abort_unless($plan, 404);
            $approval = DB::table('vision_question_approvals')->where('organization_id', $context->organizationId)
                ->where('brand_id', $context->brandId)->where('question_set_id', $plan->question_set_id)
                ->where('active', true)->first();
            if (! $approval) {
                throw ValidationException::withMessages(['measurement' => 'Набор вопросов больше не утверждён.']);
            }
            $configuration = json_decode($plan->configuration, true, 32, JSON_THROW_ON_ERROR);
            $providers = json_decode($approval->providers, true, 32, JSON_THROW_ON_ERROR);
            try {
                $id = app(MeasurementRunner::class)->start($plan->question_set_id, $providers, (int) ($configuration['runs'] ?? 0));
            } catch (\InvalidArgumentException|\LogicException) {
                throw ValidationException::withMessages(['measurement' => 'Не удалось повторить замер. Проверьте набор и модели.']);
            }
            $this->enqueue($context, $id);
            app(AuditWriter::class)->record($context, new AuditEntry('vision.measurement.repeated', 'measurement', $id, after: ['source_measurement_id' => $measurement]));

            return redirect()->route('vision.measurements.index')->with('status', 'Создан новый план замера.');
        });
    }

    public function confirm(Request $request, string $measurement): mixed
    {
        $created = app(ConfirmSnapshot::class)->run($request, $measurement);

        return redirect()->route('vision.measurements.index')->with('status', $created ? 'Снимок подтверждён.' : 'Снимок уже подтверждён.');
    }

    private function enqueue(TenantContext $context, string $measurement): void
    {
        if (config('eyon.measurement_queue_enabled', false)) {
            $answers = DB::table('measurement_answers')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)
                ->where('measurement_id', $measurement)->pluck('id');
            foreach ($answers as $answer) {
                RunMeasurement::dispatch($context->organizationId, $context->brandId, $measurement, $answer)->afterCommit();
            }
        }
    }
}
