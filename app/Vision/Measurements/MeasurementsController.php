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

        return app(MeasurementList::class)->withinCurrent($request,
            fn (TenantContext $context, string $revision) => response()->json(['revision' => $revision, ...app(MeasurementList::class)->read($context, $page)]));
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
}
