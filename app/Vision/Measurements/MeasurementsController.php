<?php

namespace App\Vision\Measurements;

use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use App\Vision\Core\MeasurementRunner;
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

    public function data(Request $request): mixed
    {
        $page = filter_var($request->query('page', 1), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
        abort_if($page === false, 404);
        $detailed = $request->query('view') === 'details';

        return app(MeasurementList::class)->withinCurrent($request,
            fn (TenantContext $context, string $revision) => response()->json(['revision' => $revision,
                ...($detailed ? app(MeasurementList::class)->readDetailed($context, $page) : app(MeasurementList::class)->read($context, $page))]));
    }

    public function revision(Request $request): mixed
    {
        return app(MeasurementList::class)->withinCurrent($request,
            fn (TenantContext $context, string $revision) => response()->json(['revision' => $revision]));
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
            try {
                $id = app(MeasurementRunner::class)->start($approval->question_set_id, $providers, (int) $data['runs']);
            } catch (\InvalidArgumentException|\LogicException) {
                throw ValidationException::withMessages(['runs' => 'Не удалось подготовить замер. Проверьте вопросы и доступные модели.']);
            }
            app(AuditWriter::class)->record($context, new AuditEntry('vision.measurement.started', 'measurement', $id, after: ['runs' => (int) $data['runs']]));

            return redirect()->route('vision.measurements.index')->with('status', 'Замер создан и ожидает выполнения.');
        });
    }

    public function next(Request $request, string $measurement): mixed
    {
        $answer = app(MeasurementList::class)->withinCurrent($request, function (TenantContext $context) use ($measurement): ?string {
            Gate::authorize('vision.measure.run');
            abort_unless(config('runtime.ai_driver', 'fake') === 'fake', 403);
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
            app(MeasurementRunner::class)->measure($answer);
        } catch (\LogicException) {
            return redirect()->route('vision.measurements.index')->with('status', 'Слот не удалось выполнить. Проверьте его состояние.');
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
            app(AuditWriter::class)->record($context, new AuditEntry('vision.measurement.repeated', 'measurement', $id, after: ['source_measurement_id' => $measurement]));

            return redirect()->route('vision.measurements.index')->with('status', 'Создан новый план замера.');
        });
    }

    public function confirm(Request $request, string $measurement): mixed
    {
        $created = app(ConfirmSnapshot::class)->run($request, $measurement);

        return redirect()->route('vision.measurements.index')->with('status', $created ? 'Снимок подтверждён.' : 'Снимок уже подтверждён.');
    }
}
