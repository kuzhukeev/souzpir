<?php

namespace App\Vision\Profile;

use App\Privacy\VerifiedHttps;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Billing\BillingAccess;
use App\Shell\Billing\VisionPlanSummary;
use App\Tenancy\TenantContext;
use App\Vision\Core\Profiles\ProfileAccess;
use App\Vision\Core\Profiles\Questionnaire;
use App\Vision\Core\Profiles\QuizConflict;
use App\Vision\Site\Jobs\AuditSiteJob;
use App\Vision\SiteAudit\Knowledge\SiteDraftStore;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use InvalidArgumentException;
use Throwable;

/** HTTP boundary: never send private input or original exceptions to the reporter. */
final class ProfileController
{
    public function startPage(#[\SensitiveParameter] Request $request): mixed
    {
        return $this->guard($request, function () use ($request) {
            $allowed = Gate::allows('vision.quiz.write');
            $state = $allowed ? $this->index($request) : ['current' => null, 'profile_id' => null, 'history' => [], 'brand' => null];

            $plan = Gate::allows('billing.read') ? app(AuthSessionRegistry::class)->withinActive($request, function (): array {
                app(BillingAccess::class)->lock(false);

                return app(VisionPlanSummary::class)->read(app(TenantContext::class));
            }) : null;

            return Inertia::render('Vision/Profile/Start', ['current' => $state['current'], 'profile_id' => $state['profile_id'], 'can_edit' => $allowed, 'secure_context' => $this->secureContext($request), 'local_http' => $this->localHttp($request), 'plan' => $plan]);
        });
    }

    /** Neutral HTML only: private props are withheld until this browser demonstrates Web Crypto. */
    public function privateAccess(#[\SensitiveParameter] Request $request): mixed
    {
        return $this->guard($request, function () use ($request) {
            app(ProfileAccess::class)->run($request, static fn () => null);
            $request->session()->reflash();
            $challenge = bin2hex(random_bytes(32));
            $nonce = base64_encode(random_bytes(18));
            $request->session()->put('vision.private_challenge', $challenge);
            $html = <<<'HTML'
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Защита анкеты</title><style>body{font:16px system-ui,sans-serif;color:#182a43;max-width:42rem;margin:12vh auto;padding:1.5rem;line-height:1.6}p{font-size:16px}</style></head><body><h1>Открываем защищённую анкету</h1><p id="failure" hidden>Для защиты ответов нужен браузер с Web Crypto и HTTPS или localhost. Включите JavaScript и откройте защищённый адрес.</p><noscript><p>Для защиты ответов включите JavaScript и используйте браузер с Web Crypto.</p></noscript><form id="verify" method="post" action="/vision/private-access"><input type="hidden" name="_token" value="__CSRF__"><input type="hidden" name="proof" id="proof"></form><script nonce="__NONCE__">(async()=>{try{if(!globalThis.crypto?.subtle)throw 0;const h='__CHALLENGE__';const a=new Uint8Array(h.match(/../g).map(x=>parseInt(x,16)));const d=await crypto.subtle.digest('SHA-256',a);document.getElementById('proof').value=Array.from(new Uint8Array(d),x=>x.toString(16).padStart(2,'0')).join('');document.getElementById('verify').submit()}catch{document.getElementById('failure').hidden=false}})()</script></body></html>
HTML;
            $html = str_replace(['__CSRF__', '__NONCE__', '__CHALLENGE__'], [e(csrf_token()), e($nonce), $challenge], $html);

            return response($html)->header('Content-Type', 'text/html; charset=UTF-8')->header('Content-Security-Policy', "default-src 'none'; script-src 'nonce-{$nonce}'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'")->header('Referrer-Policy', 'no-referrer');
        });
    }

    public function verifyPrivateAccess(#[\SensitiveParameter] Request $request): mixed
    {
        return $this->guard($request, function () use ($request) {
            app(ProfileAccess::class)->run($request, static fn () => null);
            $challenge = $request->session()->pull('vision.private_challenge');
            $next = $request->session()->pull('vision.private_next');
            $proof = $request->input('proof');
            if (! is_string($challenge) || ! preg_match('/^[a-f0-9]{64}$/D', $challenge) || ! is_string($proof)
                || ! hash_equals(hash('sha256', hex2bin($challenge)), $proof) || ! is_string($next)
                || ! preg_match('~^/vision/(?:quiz/[1-8]|profile(?:/[0-9A-HJKMNP-TV-Z]{26})?)$~iD', $next)) {
                return response('Не удалось проверить защиту браузера.', 403);
            }
            $request->session()->reflash();
            $request->session()->put('vision.private_once', ['path' => $next, 'brand' => $request->session()->get('brand_id'), 'user' => $request->user()->getAuthIdentifier(), 'version' => $request->session()->get('auth.version')]);

            return redirect($next);
        });
    }

    public function start(#[\SensitiveParameter] Request $request): mixed
    {
        return $this->guard($request, function () use ($request) {
            return app(ProfileAccess::class)->run($request, function () use ($request) {
                $state = $this->index($request);
                if ($state['profile_id'] && ($state['current']['status'] ?? null) !== 'draft') {
                    return redirect()->route('vision.profile.show');
                }
                $quiz = app(Questionnaire::class)->start($request);
                $read = app(Questionnaire::class)->read($request, $quiz['id']);

                return redirect()->route('vision.quiz.show', ['step' => $read['current_step']]);
            });
        });
    }

    public function quiz(#[\SensitiveParameter] Request $request, int $step): mixed
    {
        return $this->guard($request, function () use ($request, $step) {
            if ($step < 1 || $step > 8) {
                return response('Страница не найдена.', 404);
            }
            $state = $this->index($request);
            if (! $state['current'] || $state['current']['status'] !== 'draft') {
                return redirect()->route('vision.start');
            }
            $quiz = app(Questionnaire::class)->read($request, $state['current']['quiz_id']);

            $savedAt = $quiz['revision'] > 1 ? app(ProfileAccess::class)->run($request, fn (TenantContext $tenant) => DB::table('visibility_quizzes')
                ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('id', $quiz['id'])->value('updated_at')) : null;

            return Inertia::render('Vision/Profile/Quiz', ['quiz' => $quiz, 'step' => $step, 'brand' => $state['brand'], 'savedAt' => $savedAt, 'hints' => $this->hints($request, $step, $state['brand']['website'] ?? null), 'limits' => QuizLimits::configured()->values()]);
        });
    }

    public function save(#[\SensitiveParameter] Request $request, int $step): mixed
    {
        return $this->guard($request, function () use ($request, $step) {
            $data = $this->input($request, true);
            $this->checkLimits($step, $data['values']);
            app(Questionnaire::class)->saveStep($request, $data['id'], $data['revision'], $step, $data['values']);
            Inertia::clearHistory();

            $target = '/vision/quiz/'.min(8, $step + 1);

            return $request->expectsJson() ? response()->json(['ok' => true, 'redirect' => $target]) : redirect($target);
        });
    }

    public function submit(#[\SensitiveParameter] Request $request): mixed
    {
        return $this->guard($request, function () use ($request) {
            $data = $this->input($request, true, false);
            $draft = app(Questionnaire::class)->read($request, $data['id']);
            if ($draft['revision'] !== $data['revision']) {
                throw new QuizConflict('Questionnaire was changed.');
            }
            app(CompletionRules::class)->validate($draft['answers']);
            app(Questionnaire::class)->submit($request, $data['id'], $data['revision']);
            Inertia::clearHistory();
            $request->session()->flash('vision.quiz_submitted', true);

            return $request->expectsJson() ? response()->json(['ok' => true, 'redirect' => '/vision/profile']) : redirect()->route('vision.profile.show')->with('status', 'Анкета отправлена.');
        });
    }

    public function profile(#[\SensitiveParameter] Request $request, ?string $profileId = null): mixed
    {
        return $this->guard($request, function () use ($request, $profileId) {
            $state = $this->index($request);
            if (! $state['profile_id']) {
                return redirect()->route('vision.start');
            }
            $profile = app(Questionnaire::class)->profile($request, $profileId ?? $state['profile_id']);
            $previousId = app(ProfileAccess::class)->run($request, fn (TenantContext $context) => DB::table('brand_profiles')
                ->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)
                ->where('version', '<', $profile['version'])->orderByDesc('version')->value('id'));
            $previous = $previousId ? app(Questionnaire::class)->profile($request, $previousId) : null;

            return Inertia::render('Vision/Profile/Show', ['profile' => $profile, 'previous_profile' => $previous, 'history' => $state['history'], 'current_profile_id' => $state['profile_id'], 'is_latest' => $profile['id'] === $state['profile_id'], 'submitted' => (bool) $request->session()->get('vision.quiz_submitted', false), 'limits' => QuizLimits::configured()->values()]);
        });
    }

    public function edit(#[\SensitiveParameter] Request $request, int $step): mixed
    {
        return $this->guard($request, function () use ($request, $step) {
            $data = $this->input($request, false);
            $this->checkLimits($step, $data['values']);
            $current = app(Questionnaire::class)->profile($request, $data['id']);
            if ($this->index($request)['profile_id'] !== $data['id']) {
                throw new QuizConflict('Profile was changed.');
            }
            $answers = $current['answers'];
            $answers['step'.$step] = $data['values'];
            app(CompletionRules::class)->validate($answers);
            app(Questionnaire::class)->edit($request, $data['id'], $step, $data['values']);
            Inertia::clearHistory();
            $request->session()->flash('vision.quiz_submitted', true);

            return $request->expectsJson() ? response()->json(['ok' => true, 'redirect' => '/vision/profile']) : redirect()->route('vision.profile.show')->with('status', 'Профиль обновлён.');
        });
    }

    /** Read the current IDs under the same session, tenant, entitlement and ability locks as the core API. */
    private function index(#[\SensitiveParameter] Request $request): array
    {
        return app(ProfileAccess::class)->run($request, function (TenantContext $context): array {
            $tenant = ['organization_id' => $context->organizationId, 'brand_id' => $context->brandId];
            $quiz = DB::table('visibility_quizzes')->where($tenant)->orderByDesc('version')->first(['id', 'status', 'current_step', 'revision']);
            $history = DB::table('brand_profiles')->where($tenant)->orderByDesc('version')->limit(100)->get(['id', 'version', 'source', 'created_at']);
            $brand = DB::table('brands')->where('id', $context->brandId)->where('organization_id', $context->organizationId)->first(['id', 'name', 'theme', 'website']);

            return [
                'current' => $quiz ? ['quiz_id' => $quiz->id, 'revision' => (int) $quiz->revision, 'current_step' => (int) $quiz->current_step, 'status' => $quiz->status] : null,
                'profile_id' => $history->first()?->id,
                'history' => $history->map(fn ($row) => ['id' => $row->id, 'version' => (int) $row->version, 'source' => $row->source, 'created_at' => $row->created_at])->all(),
                'brand' => ['id' => $brand?->id, 'name' => $brand?->name, 'theme' => $brand?->theme, 'website' => $brand?->website],
            ];
        });
    }

    /** Read only an already stored and audited page; never crawl or contact its URL. */
    private function hints(#[\SensitiveParameter] Request $request, int $step, ?string $website): array
    {
        $unavailable = ['status' => 'unavailable', 'items' => []];
        if (! in_array($step, [1, 2, 3, 4, 5, 7], true) || ! Gate::allows('vision.settings.write') || ! $website) {
            return $unavailable;
        }
        try {
            $sources = app(ProfileAccess::class)->run($request, function (TenantContext $context) use ($website) {
                $currentWebsite = DB::table('brands')->where('id', $context->brandId)->where('organization_id', $context->organizationId)->value('website');
                if ($currentWebsite !== $website) {
                    return null;
                }

                $page = app(SiteDraftStore::class)->read($context, $website);
                $raw = DB::table('audit_runs')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)->orderByDesc('created_at')->orderByDesc('id')->value('result');
                $audit = is_string($raw) ? json_decode($raw, true) : null;
                $host = strtolower((string) parse_url($website, PHP_URL_HOST));
                if (! is_array($audit) || $host === '' || ($audit['url'] ?? null) !== $website) {
                    $audit = null;
                }

                return ['page' => $page, 'audit' => $audit, 'progress' => Cache::get(AuditSiteJob::key($context->organizationId, $context->brandId))];
            });
            $progress = is_string($website) && $website !== '' && ($sources['progress']['url'] ?? null) === $website ? ($sources['progress']['stage'] ?? null) : null;
            $siteCheck = ['stage' => in_array($progress, ['queued', 'inspecting', 'saving', 'complete', 'failed'], true) ? $progress : null, 'readiness' => $sources['audit']['readiness'] ?? null, 'pages' => $sources['audit']['crawled_pages'] ?? null];
            $page = $sources['page'] ?? null;
            $audit = $sources['audit'] ?? null;
            if (! $page && ! $audit) {
                return ['status' => 'empty', 'items' => [], 'site_check' => $siteCheck];
            }
            $page ??= [];
            $title = trim((string) ($page['title'] ?? ''));
            $source = (string) ($page['source_url'] ?? $website);
            $items = [];
            if (in_array($step, [2, 5], true) && $title !== '') {
                $items[] = ['label' => 'Заголовок сайта', 'value' => mb_substr($title, 0, 180), 'source' => $source];
            }
            if (in_array($step, [3, 4, 7], true)) {
                $prefix = match ($step) {
                    3 => 'Категории|Товары|Услуги',
                    4 => 'Конкуренты',
                    7 => 'Вопросы клиентов',
                };
                $text = (string) ($page['content_text'] ?? '');
                if (preg_match('~^(?:'.$prefix.'):\s*(.+)$~umi', $text, $matches)) {
                    foreach (array_slice(preg_split('/\s*[;,]\s*/u', $matches[1]) ?: [], 0, 5) as $value) {
                        $value = trim($value);
                        if ($value !== '') {
                            $items[] = ['label' => 'С сайта', 'value' => mb_substr($value, 0, 180), 'source' => $source];
                        }
                    }
                }
            }

            if ($step === 5) {
                $text = (string) ($page['content_text'] ?? '');
                preg_match_all('/^(Адрес|Телефон|Год основания|Сертификаты|Доставка):\h*([^\r\n]{1,180})$/umi', $text, $facts, PREG_SET_ORDER);
                foreach ($facts as $fact) {
                    $value = trim($fact[2]);
                    if ($value !== '' && ! in_array($value, array_column($items, 'value'), true)) {
                        $items[] = ['label' => 'Данные сайта: '.$fact[1], 'value' => $value, 'source' => $source];
                    }
                    if (count($items) >= 5) {
                        break;
                    }
                }
            }

            if ($step === 3 && preg_match('/^Типы покупателей:\h*([^\r\n]+)$/umi', (string) ($page['content_text'] ?? ''), $audience)) {
                foreach (array_slice(preg_split('/\s*[;,]\s*/u', $audience[1]) ?: [], 0, 5) as $value) {
                    if (trim($value) !== '') {
                        $items[] = ['label' => 'Тип покупателя', 'value' => mb_substr(trim($value), 0, 180), 'source' => $source, 'kind' => 'customer_type'];
                    }
                }
            }

            if ($step === 3) {
                foreach (($audit['pages'] ?? []) as $auditedPage) {
                    $pageUrl = is_array($auditedPage) ? ($auditedPage['url'] ?? null) : null;
                    $types = is_array($auditedPage['structured_types'] ?? null) ? $auditedPage['structured_types'] : [];
                    if (! is_string($pageUrl) || strtolower((string) parse_url($pageUrl, PHP_URL_HOST)) !== strtolower((string) parse_url($website, PHP_URL_HOST))
                        || ! is_numeric($auditedPage['status'] ?? null) || $auditedPage['status'] < 200 || $auditedPage['status'] >= 300
                        || count($types) !== 1 || ! in_array($types[0], ['Product', 'Service'], true)) {
                        continue;
                    }
                    foreach (is_array($auditedPage['structured_facts'] ?? null) ? $auditedPage['structured_facts'] : [] as $fact) {
                        if (! is_array($fact) || ($fact['label'] ?? null) !== 'Название' || ! is_string($fact['value'] ?? null) || trim($fact['value']) === '') {
                            continue;
                        }
                        $value = mb_substr(trim($fact['value']), 0, 180);
                        if (! in_array($value, array_column($items, 'value'), true)) {
                            $items[] = ['label' => $types[0] === 'Product' ? 'Товар из аудита' : 'Услуга из аудита', 'value' => $value, 'source' => $pageUrl];
                        }
                        if (count($items) >= 5) {
                            break 2;
                        }
                    }
                }
            }

            if ($step === 5) {
                foreach (($audit['pages'] ?? []) as $auditedPage) {
                    $pageUrl = is_array($auditedPage) ? ($auditedPage['url'] ?? null) : null;
                    if (! is_string($pageUrl) || strtolower((string) parse_url($pageUrl, PHP_URL_HOST)) !== strtolower((string) parse_url($website, PHP_URL_HOST))
                        || ! is_numeric($auditedPage['status'] ?? null) || $auditedPage['status'] < 200 || $auditedPage['status'] >= 300) {
                        continue;
                    }
                    foreach (is_array($auditedPage['structured_facts'] ?? null) ? $auditedPage['structured_facts'] : [] as $fact) {
                        if (! is_array($fact) || ! in_array($fact['label'] ?? null, ['Название', 'Описание'], true) || ! is_string($fact['value'] ?? null) || trim($fact['value']) === '') {
                            continue;
                        }
                        $value = mb_substr(trim($fact['value']), 0, 180);
                        if (! in_array($value, array_column($items, 'value'), true)) {
                            $items[] = ['label' => 'Данные сайта: '.$fact['label'], 'value' => $value, 'source' => $pageUrl];
                        }
                        if (count($items) >= 5) {
                            break 2;
                        }
                    }
                }
            }

            if ($step === 5 && is_array($audit)) {
                foreach (app(AuditedFulfilmentHints::class)->extract($audit, $website) as $hint) {
                    if (! in_array($hint['value'], array_column($items, 'value'), true) && count($items) < 5) {
                        $items[] = $hint;
                    }
                }
            }

            if (in_array($step, [3, 4, 7], true) && is_array($audit)) {
                foreach (app(AuditedExplicitHints::class)->extract($audit, $website, $step) as $hint) {
                    if (! in_array($hint['value'], array_column($items, 'value'), true) && count($items) < 5) {
                        $items[] = $hint;
                    }
                }
            }

            if (in_array($step, [3, 5, 7], true)) {
                foreach (($audit['pages'] ?? []) as $auditedPage) {
                    if (! is_array($auditedPage) || ! is_string($auditedPage['url'] ?? null)
                        || strtolower((string) parse_url($auditedPage['url'], PHP_URL_HOST)) !== strtolower((string) parse_url($website, PHP_URL_HOST))
                        || ($auditedPage['status'] ?? 0) < 200 || ($auditedPage['status'] ?? 0) >= 300) {
                        continue;
                    }
                    foreach (($auditedPage['headings'] ?? []) as $heading) {
                        if (! is_string($heading) || trim($heading) === '' || ($step === 7 && ! str_ends_with(trim($heading), '?'))) {
                            continue;
                        }
                        $value = mb_substr(trim($heading), 0, 180);
                        if (! in_array($value, array_column($items, 'value'), true)) {
                            $items[] = ['label' => 'Заголовок из аудита', 'value' => $value, 'source' => (string) ($auditedPage['url'] ?? $website)];
                        }
                        if (count($items) >= 5) {
                            break 2;
                        }
                    }
                }
            }

            return ['status' => $items || ($step === 1 && $page) ? 'available' : 'empty', 'items' => $items, 'site_check' => $siteCheck];
        } catch (Throwable) {
            // Local failure must not block the form or expose private source data in logs.
            return $unavailable;
        }
    }

    private function input(#[\SensitiveParameter] Request $request, bool $quiz, bool $needsValues = true): array
    {
        $id = $request->input($quiz ? 'quiz_id' : 'profile_id');
        $revision = $request->input('revision');
        $values = $needsValues ? $request->input('values') : [];
        if (! is_string($id) || ! Str::isUlid($id) || ! is_array($values)
            || ($quiz && (! is_int($revision) && (! is_string($revision) || ! preg_match('/^[1-9][0-9]{0,8}$/D', $revision))))) {
            throw new InvalidArgumentException('Invalid input.');
        }
        if ($quiz && ((int) $revision < 1 || (int) $revision > 999999999)) {
            throw new InvalidArgumentException('Invalid revision.');
        }

        return ['id' => $id, 'revision' => (int) $revision, 'values' => $values];
    }

    private function checkLimits(int $step, #[\SensitiveParameter] array $values): void
    {
        $field = match ($step) {
            4 => 'competitors', 5 => 'facts', 7 => 'questions', default => null
        };
        if ($field !== null && array_key_exists($field, $values)
            && (! is_array($values[$field]) || ! QuizLimits::configured()->permits($field, $values[$field]))) {
            throw new InvalidArgumentException('Questionnaire collection exceeds its configured limit.');
        }
    }

    private function guard(#[\SensitiveParameter] Request $request, #[\SensitiveParameter] Closure $action): mixed
    {
        try {
            if (! $request->routeIs('vision.start') && ! $this->secureContext($request)) {
                return $this->error($request, 'Анкета доступна по HTTPS или на localhost.', 403);
            }
            if ($request->routeIs('vision.quiz.show', 'vision.profile.show', 'vision.profile.version') && ! $this->localHttp($request)) {
                $path = $request->getPathInfo();
                $grant = $request->session()->pull('vision.private_once');
                $expected = ['path' => $path, 'brand' => $request->session()->get('brand_id'), 'user' => $request->user()->getAuthIdentifier(), 'version' => $request->session()->get('auth.version')];
                if (! is_array($grant) || $grant !== $expected) {
                    $request->session()->put('vision.private_next', $path);
                    $request->session()->reflash();

                    return $request->header('X-Inertia') ? Inertia::location('/vision/private-access') : redirect('/vision/private-access');
                }
                Inertia::encryptHistory();
            }

            return $action();
        } catch (QuizConflict) {
            return $this->error($request, 'Данные изменились в другой вкладке. Обновите страницу и повторите.', 409);
        } catch (InvalidArgumentException) {
            return $this->error($request, 'Проверьте заполнение анкеты и допустимые ограничения.', 422);
        } catch (Throwable) {
            // Never report the throwable: its trace may contain private questionnaire values.
            return $this->error($request, 'Не удалось выполнить действие. Проверьте доступ и повторите попытку.', 403);
        }
    }

    private function secureContext(Request $request): bool
    {
        return VerifiedHttps::allows($request)
            || (app()->environment('local', 'testing') && VerifiedHttps::directLoopback($request) && in_array(strtolower($request->getHost()), ['localhost', '127.0.0.1', '[::1]', '::1'], true))
            || $this->localHttp($request);
    }

    /** The named OSPanel host has no Web Crypto over HTTP; never enable this in production. */
    private function localHttp(Request $request): bool
    {
        return app()->environment('local')
            && ! VerifiedHttps::allows($request)
            && strtolower($request->getHost()) === 'souzpir.local'
            && VerifiedHttps::directLoopback($request);
    }

    private function error(#[\SensitiveParameter] Request $request, string $message, int $status): mixed
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], $status);
        }
        if ($request->isMethodSafe()) {
            return response($message, $status);
        }

        return back()->withErrors(['form' => $message]);
    }
}
