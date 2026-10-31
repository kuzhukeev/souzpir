<?php

namespace App\Shell\Brands\Http;

use App\Shell\Brands\Actions\BrandSettings;
use App\Shell\Brands\BrandAccess;
use App\Shell\Brands\BrandDomain;
use App\Shell\Brands\Network\PublicHost;
use App\Shell\Organizations\ModuleEntitlements;
use App\Shell\Plans\Contracts\PlanLimits;
use App\Shell\Plans\Contracts\UsageQuery;
use App\Shell\Plans\Contracts\UsageReader;
use App\Tenancy\TenantContextRunner;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class BrandSettingsController
{
    public function __construct(private readonly BrandAccess $access, private readonly BrandSettings $settings) {}

    public function show(Request $request, string $brand, ModuleEntitlements $entitlements, PlanLimits $limits, UsageReader $usage): Response
    {
        $target = $this->access->read($request, $brand);
        $ctx = $this->access->context($brand);
        $moduleItems = [];
        foreach (['seller', 'vision'] as $module) {
            $limit = $limits->get($ctx, 'modules.'.$module);
            $metric = $module === 'seller' ? 'seller.dialogues' : 'vision.measurements';
            $from = $module === 'seller' ? CarbonImmutable::now('UTC')->startOfMonth() : CarbonImmutable::now('UTC')->startOfWeek();
            $measurement = ['value' => null, 'unit' => '', 'status' => 'unavailable'];
            if ($target->status === 'active') {
                $measurement = app(TenantContextRunner::class)->run($ctx, fn () => $usage->read($ctx, new UsageQuery($from, CarbonImmutable::now('UTC'), [$metric]))->metrics[$metric]);
            }
            $quota = $limits->get($ctx, $module === 'seller' ? 'seller.dialogs_month' : 'vision.runs_per_week');
            $moduleItems[$module] = [
                'usage' => $measurement, 'quota' => ['value' => $quota->value, 'configured' => $quota->configured, 'unit' => $quota->unit],
                'enabled' => $entitlements->enabled($ctx, $module),
                'configured_enabled' => (bool) DB::table('brand_modules')->where('organization_id', $ctx->organizationId)->where('brand_id', $brand)->where('module', $module)->value('enabled'),
                'allowed' => $entitlements->allowedByPlan($ctx, $module),
                'limit' => ['value' => $limit->value, 'configured' => $limit->configured, 'unit' => $limit->unit],
            ];
        }
        $domains = BrandDomain::withoutGlobalScopes()->where('organization_id', $ctx->organizationId)->where('brand_id', $brand)->orderBy('host')->get()->map(fn (BrandDomain $domain) => [
            'id' => $domain->id, 'host' => $domain->host, 'purpose' => $domain->purpose, 'verification_method' => $domain->verification_method,
            'status' => $domain->status, 'verified_at' => $domain->verified_at?->toIso8601String(), 'last_checked_at' => $domain->last_checked_at?->toIso8601String(), 'last_error' => $domain->last_error, 'verification' => $domain->instructions(),
        ]);
        $appearance = $target->settings['theme'] ?? [];

        return Inertia::render('Shell/Brands/Settings', [
            'targetBrand' => ['id' => $target->id, 'name' => $target->name, 'website' => $target->website, 'industry' => $target->industry, 'market' => $target->market, 'language' => $target->language, 'timezone' => $target->timezone,
                'theme' => $target->theme, 'status' => $target->status, 'logo_url' => ($target->settings['logo_path'] ?? null) ? route('brands.settings.logo', $brand) : null,
                'website_check' => $target->settings['website_check'] ?? null,
                'settings' => ['aliases' => $target->settings['aliases'] ?? [], 'theme' => ['subtitle' => $appearance['subtitle'] ?? '', 'promo_card' => $appearance['promo_card'] ?? true]]],
            'domains' => $domains, 'moduleItems' => $moduleItems, 'networkChecksEnabled' => config('features.E1-05.defaults.external_checks', false) === true, 'permissions' => ['manage' => $target->status === 'active'],
        ]);
    }

    public function general(Request $request, string $brand): RedirectResponse
    {
        $this->access->read($request, $brand);
        $data = $this->validate($request, $brand, [
            'name' => ['required', 'string', 'max:160'], 'website' => ['nullable', 'url:http,https', 'max:2048'],
            'industry' => ['nullable', 'string', 'max:120'], 'market' => ['nullable', 'string', 'max:120'],
            'language' => ['sometimes', 'required', 'string', 'max:16', 'regex:/^[a-z]{2,3}(?:-[A-Z]{2})?$/'],
            'timezone' => ['sometimes', 'required', 'timezone'], 'aliases' => ['sometimes', 'array', 'max:20'], 'aliases.*' => ['string', 'max:160'],
        ]);
        if (trim($data['name']) === '') {
            $this->invalid($brand, 'name', 'Укажите название бренда.');
        }
        if ($data['website'] ?? null) {
            $parts = parse_url($data['website']);
            try {
                PublicHost::normalize($parts['host'] ?? '');
            } catch (InvalidArgumentException) {
                $this->invalid($brand, 'website', 'Укажите адрес публичного сайта.');
            }
            if (isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== (($parts['scheme'] ?? '') === 'https' ? 443 : 80))) {
                $this->invalid($brand, 'website', 'Адрес не должен содержать логин, пароль или нестандартный порт.');
            }
        }
        $this->settings->general($request, $brand, $data);

        return $this->back($brand);
    }

    public function theme(Request $request, string $brand): RedirectResponse
    {
        $this->access->read($request, $brand);
        $data = $this->validate($request, $brand, [
            'theme' => ['required', Rule::in(['confectionery', 'pyro', 'neutral'])], 'subtitle' => ['nullable', 'string', 'max:60'], 'promo_card' => ['required', 'boolean'],
            'logo' => ['nullable', 'file', 'max:2048', 'mimes:png,jpg,jpeg,webp'], 'remove_logo' => ['sometimes', 'boolean'],
        ]);
        if ($request->hasFile('logo') && ($data['remove_logo'] ?? false)) {
            $this->invalid($brand, 'logo', 'Выберите загрузку или удаление логотипа.');
        }
        $png = null;
        if ($request->hasFile('logo')) {
            $bytes = file_get_contents($request->file('logo')->getRealPath());
            $info = $bytes === false ? false : @getimagesizefromstring($bytes);
            if (! $info || ! in_array($info['mime'] ?? '', ['image/png', 'image/jpeg', 'image/webp'], true) || $info[0] < 1 || $info[1] < 1 || $info[0] > 2048 || $info[1] > 2048) {
                $this->invalid($brand, 'logo', 'Выберите PNG, JPEG или WebP до 2 МБ и 2048 × 2048 пикселей.');
            }
            $image = @imagecreatefromstring($bytes);
            if ($image === false) {
                $this->invalid($brand, 'logo', 'Не удалось прочитать изображение.');
            }
            imagesavealpha($image, true);
            ob_start();
            $success = imagepng($image, null, 6);
            $png = ob_get_clean();
            imagedestroy($image);
            if (! $success || ! is_string($png) || strlen($png) > 4 * 1024 * 1024) {
                $this->invalid($brand, 'logo', 'Не удалось подготовить изображение.');
            }
        }
        $this->settings->theme($request, $brand, $data, $png);

        return $this->back($brand);
    }

    public function addDomain(Request $request, string $brand): RedirectResponse
    {
        $this->access->read($request, $brand);
        $data = $this->validate($request, $brand, ['host' => ['required', 'string', 'max:253'], 'purpose' => ['required', Rule::in(['widget', 'widget_and_visibility'])], 'verification_method' => ['required', Rule::in(['file', 'dns'])]]);
        try {
            $data['host'] = PublicHost::normalize($data['host']);
        } catch (InvalidArgumentException $error) {
            $this->invalid($brand, 'host', $error->getMessage());
        }
        $this->settings->addDomain($request, $brand, $data);

        return $this->back($brand);
    }

    public function verifyDomain(Request $request, string $brand, string $domain): RedirectResponse
    {
        $this->settings->verifyDomain($request, $brand, $domain);

        return $this->back($brand);
    }

    public function removeDomain(Request $request, string $brand, string $domain): RedirectResponse
    {
        $this->settings->removeDomain($request, $brand, $domain);

        return $this->back($brand);
    }

    public function checkWebsite(Request $request, string $brand): RedirectResponse
    {
        $this->settings->checkWebsite($request, $brand);

        return $this->back($brand);
    }

    public function logo(Request $request, string $brand): HttpResponse
    {
        $target = $this->access->read($request, $brand, 'brand.read');
        $path = $target->settings['logo_path'] ?? null;
        abort_unless(is_string($path) && BrandSettings::validLogoPath($target->organization_id, $path) && Storage::disk('local')->exists($path), 404);

        return response(Storage::disk('local')->get($path), 200, ['Content-Type' => 'image/png', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    private function validate(Request $request, string $brand, array $rules): array
    {
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            throw (new ValidationException($validator))->redirectTo(route('brands.settings.show', $brand));
        }

        return $validator->validated();
    }

    private function invalid(string $brand, string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message])->redirectTo(route('brands.settings.show', $brand));
    }

    private function back(string $brand): RedirectResponse
    {
        return redirect()->route('brands.settings.show', $brand);
    }
}
