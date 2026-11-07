<?php

namespace App\Vision\Questions;

use App\Privacy\VerifiedHttps;
use App\Vision\Measurements\MeasurementList;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use InvalidArgumentException;
use Throwable;

final class QuestionsController
{
    public function index(): mixed
    {
        return Inertia::render('Vision/Questions/Index');
    }

    public function approvePage(Request $request): mixed
    {
        return app(MeasurementList::class)->withinCurrent($request,
            fn ($context, string $revision) => Inertia::render('Vision/Questions/Approve', ['measurementBrandRevision' => $revision]), false);
    }

    public function active(): mixed
    {
        return Inertia::render('Vision/Questions/Active');
    }

    public function history(): mixed
    {
        return Inertia::render('Vision/Questions/History');
    }

    public function data(#[\SensitiveParameter] Request $request): mixed
    {
        return $this->guard(fn () => response()->json(app(QuestionWorkspace::class)->read($request)));
    }

    public function add(#[\SensitiveParameter] Request $request): mixed
    {
        return $this->guard(fn () => response()->json(['id' => app(QuestionWorkspace::class)->add($request, $request->only(['text', 'intent']))], 201));
    }

    public function importProfile(#[\SensitiveParameter] Request $request): mixed
    {
        return $this->guard(function () use ($request) {
            $intent = $request->input('intent');
            if (! is_string($intent)) {
                throw new InvalidArgumentException('Invalid intent.');
            }

            return response()->json(app(QuestionWorkspace::class)->importProfile($request, $intent));
        });
    }

    public function copyActive(#[\SensitiveParameter] Request $request): mixed
    {
        return $this->guard(fn () => response()->json(app(QuestionWorkspace::class)->copyActive($request)));
    }

    public function change(#[\SensitiveParameter] Request $request, string $id): mixed
    {
        return $this->guard(function () use ($request, $id) {
            app(QuestionWorkspace::class)->change($request, $id, $request->all());

            return response()->json(['ok' => true]);
        });
    }

    public function delete(#[\SensitiveParameter] Request $request, string $id): mixed
    {
        return $this->guard(function () use ($request, $id) {
            app(QuestionWorkspace::class)->change($request, $id, [], true);

            return response()->json(['ok' => true]);
        });
    }

    public function approve(#[\SensitiveParameter] Request $request): mixed
    {
        return $this->guard(function () use ($request) {
            $ids = $request->input('candidate_ids');
            $models = $request->input('models');
            $frequency = $request->input('frequency');
            $schedule = $request->input('schedule');
            if (! is_array($ids) || ! is_array($models) || ($frequency !== null && ! is_string($frequency))
                || ($schedule !== null && ! is_array($schedule))) {
                throw new InvalidArgumentException('Invalid input.');
            }
            $id = app(QuestionWorkspace::class)->approve($request, $ids, $models, $frequency, $schedule);

            return response()->json(['ok' => true, 'id' => $id]);
        });
    }

    private function guard(#[\SensitiveParameter] Closure $callback): mixed
    {
        $request = request();
        $localHttp = app()->environment('local')
            && strtolower($request->getHost()) === 'souzpir.local'
            && VerifiedHttps::directLoopback($request);
        if (! VerifiedHttps::allows($request) && ! $localHttp) {
            return response()->json(['message' => 'Для работы с вопросами откройте кабинет по HTTPS или на localhost.'], 403);
        }
        try {
            return $callback();
        } catch (InvalidArgumentException|ValidationException) {
            return response()->json(['message' => 'Проверьте данные, выбранные модели и лимиты.'], 422);
        } catch (Throwable) {
            return response()->json(['message' => 'Не удалось выполнить действие. Проверьте доступ и повторите попытку.'], 403);
        }
    }
}
