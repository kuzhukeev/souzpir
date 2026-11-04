<?php

namespace App\Shell\Onboarding\Http;

use App\Models\Brand;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Brands\Network\SafeBrandHttp;
use App\Shell\Onboarding\OwnerLock;
use App\Shell\Plans\LimitEnforcer;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class OnboardingController
{
    private const DEFAULTS = [
        'organization_name' => '', 'industry' => '', 'region' => '', 'timezone' => 'Europe/Moscow',
        'website' => '', 'brand_name' => '', 'alias' => '', 'theme' => 'neutral',
        'seller' => false, 'vision' => false,
    ];

    public function show(Request $request): Response
    {
        $context = app(TenantContext::class);
        $progress = DB::table('onboarding_progress')->where('organization_id', $context->organizationId)->first();
        $existing = DB::table('brands')->where('organization_id', $context->organizationId)->exists();
        $data = $progress ? json_decode($progress->draft, true, 128, JSON_THROW_ON_ERROR) : [];
        $initial = array_merge(self::DEFAULTS, ['organization_name' => $request->attributes->get('tenant.organization')->name], $data, [
            'step' => $progress ? (int) $progress->step : 1,
            'completed' => $progress?->completed_at !== null,
            'already_configured' => $existing && ! $progress?->completed_at,
            'logo_url' => $progress?->logo_path ? route('onboarding.logo.show', ['v' => hash('sha256', $progress->logo_path)]) : null,
            'website_check' => ($request->session()->get('website_check.organization_id') === $context->organizationId && $request->session()->get('website_check.website') === ($data['website'] ?? '')) ? $request->session()->get('website_check') : null,
        ]);

        return Inertia::render('Shell/Onboarding/Start', ['initial' => $initial]);
    }

    public function draft(Request $request): RedirectResponse
    {
        $data = $this->validated($request, false);
        $context = app(TenantContext::class);
        app(AuthSessionRegistry::class)->withinActive($request, function () use ($context, $data): void {
            OwnerLock::acquire($context);
            $progress = DB::table('onboarding_progress')->where('organization_id', $context->organizationId)->lockForUpdate()->first();
            if ($progress?->completed_at !== null || DB::table('brands')->where('organization_id', $context->organizationId)->exists()) {
                throw ValidationException::withMessages(['onboarding' => 'Первичная настройка уже завершена или у компании есть бренд.'])->redirectTo(route('onboarding.show'));
            }
            $step = $data['step'];
            unset($data['step']);
            DB::table('onboarding_progress')->updateOrInsert(['organization_id' => $context->organizationId], [
                'draft' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'step' => $step,
                'updated_at' => now(), 'created_at' => $progress?->created_at ?? now(),
            ]);
            app(AuditWriter::class)->record($context, new AuditEntry('onboarding.draft.saved', 'organization', $context->organizationId, after: ['step' => $step]));
        });

        if ($request->boolean('check_website')) {
            $website = $data['website'];
            $status = 'unavailable';
            if ($website !== '') {
                try {
                    $status = app(SafeBrandHttp::class)->get($website, 65536)->successful() ? 'available' : 'unavailable';
                } catch (Throwable) {
                    // DNS, private addresses and transport failures all mean unavailable.
                }
            }
            $request->session()->put('website_check', ['organization_id' => $context->organizationId, 'website' => $website, 'status' => $status]);
        }

        return redirect()->route('onboarding.show');
    }

    public function finish(Request $request): RedirectResponse
    {
        $data = $this->validated($request, true);
        unset($data['step']);
        $context = app(TenantContext::class);
        $result = app(AuthSessionRegistry::class)->withinActive($request, function () use ($context, $data): array {
            $organization = OwnerLock::acquire($context);
            $progress = DB::table('onboarding_progress')->where('organization_id', $context->organizationId)->lockForUpdate()->first();
            if ($progress?->completed_at !== null) {
                $saved = json_decode($progress->draft, true, 128, JSON_THROW_ON_ERROR);
                $same = count($saved) === count($data);
                foreach ($data as $key => $value) {
                    $same = $same && array_key_exists($key, $saved) && $saved[$key] === $value;
                }
                if (! $same) {
                    throw ValidationException::withMessages(['onboarding' => 'Настройка уже завершена. Изменения в этом мастере не сохранены.'])->redirectTo(route('onboarding.show'));
                }

                return ['brand_id' => $progress->brand_id, 'created' => false];
            }
            if (DB::table('brands')->where('organization_id', $context->organizationId)->exists()) {
                throw ValidationException::withMessages(['onboarding' => 'У компании уже есть бренд. Используйте настройки кабинета.'])->redirectTo(route('onboarding.show'));
            }
            try {
                app(LimitEnforcer::class)->assertWithin($context, 'brands.max', '1');
            } catch (ValidationException $exception) {
                throw $exception->redirectTo(route('onboarding.show'));
            }
            $organization->forceFill(['name' => $data['organization_name']])->save();
            $brand = Brand::create([
                'name' => $data['brand_name'], 'industry' => $data['industry'] ?: null,
                'market' => $data['region'] ?: null, 'timezone' => $data['timezone'],
                'website' => $data['website'] ?: null, 'theme' => $data['theme'],
                // This is a request, not an enabled module or permission grant.
                'settings' => ['logo_path' => $progress?->logo_path, 'onboarding' => ['aliases' => array_values(array_filter(array_map('trim', explode("\n", $data['alias'])), fn (string $alias): bool => $alias !== '')), 'modules_requested' => ['seller' => $data['seller'], 'vision' => $data['vision']]]],
            ]);
            DB::table('onboarding_progress')->updateOrInsert(['organization_id' => $context->organizationId], [
                'brand_id' => $brand->id, 'draft' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'step' => 4, 'completed_at' => now(), 'updated_at' => now(), 'created_at' => $progress?->created_at ?? now(),
            ]);
            app(AuditWriter::class)->record($context, new AuditEntry('onboarding.completed', 'brand', $brand->id, after: ['modules_requested' => ['seller' => $data['seller'], 'vision' => $data['vision']]]));

            return ['brand_id' => $brand->id, 'created' => true];
        });
        if ($result['created']) {
            $request->session()->put('brand_id', $result['brand_id']);
        }

        return redirect()->route('onboarding.show');
    }

    private function validated(Request $request, bool $final): array
    {
        $rules = [
            'organization_name' => [$final ? 'required' : 'nullable', 'string', 'max:160'],
            'industry' => ['nullable', 'string', 'max:120'], 'region' => ['nullable', 'string', 'max:120'],
            'timezone' => ['required', 'timezone'], 'website' => ['nullable', 'url:http,https', 'max:2048'],
            'brand_name' => [$final ? 'required' : 'nullable', 'string', 'max:160'],
            'alias' => ['nullable', 'string', 'max:1200'], 'theme' => ['required', Rule::in(['neutral', 'pyro', 'confectionery'])],
            'seller' => ['required', 'boolean'], 'vision' => ['required', 'boolean'], 'step' => ['required', 'integer', 'min:1', 'max:4'],
        ];
        $validator = Validator::make($request->all(), $rules);
        $validator->after(function ($validator) use ($request, $final): void {
            $aliases = $request->input('alias');
            if (is_string($aliases)) {
                foreach (explode("\n", $aliases) as $alias) {
                    if (mb_strlen(trim($alias)) > 120) {
                        $validator->errors()->add('alias', 'Каждый вариант названия должен содержать не более 120 символов.');
                        break;
                    }
                }
            }
            $url = $request->input('website');
            if (is_string($url) && $url !== '' && (parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null)) {
                $validator->errors()->add('website', 'Адрес сайта не должен содержать логин или пароль.');
            }
            if ($final && ! $request->boolean('seller') && ! $request->boolean('vision')) {
                $validator->errors()->add('modules', 'Выберите хотя бы один модуль для настройки.');
            }
        });
        if ($validator->fails()) {
            throw (new ValidationException($validator))->redirectTo(route('onboarding.show'));
        }
        $input = $validator->validated();
        $out = self::DEFAULTS;
        foreach (array_keys($out) as $key) {
            if (array_key_exists($key, $input)) {
                $out[$key] = is_string($input[$key]) ? trim($input[$key]) : ($input[$key] ?? '');
            }
        }
        $out['seller'] = $request->boolean('seller');
        $out['vision'] = $request->boolean('vision');
        if ($final && ($out['organization_name'] === '' || $out['brand_name'] === '')) {
            throw ValidationException::withMessages(['organization_name' => 'Заполните название компании и бренда.'])->redirectTo(route('onboarding.show'));
        }
        $out['step'] = (int) $input['step'];

        return $out;
    }
}
