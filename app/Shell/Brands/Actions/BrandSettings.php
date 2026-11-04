<?php

namespace App\Shell\Brands\Actions;

use App\Models\Brand;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Brands\BrandAccess;
use App\Shell\Brands\BrandDomain;
use App\Shell\Brands\Contracts\DomainDnsResolver;
use App\Shell\Brands\Network\SafeBrandHttp;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class BrandSettings
{
    public function __construct(private readonly BrandAccess $access, private readonly SafeBrandHttp $http, private readonly DomainDnsResolver $dns) {}

    public function general(Request $request, string $id, array $data): void
    {
        $this->access->change($request, $id, function (Brand $brand, TenantContext $ctx) use ($data): void {
            $settings = $brand->settings ?? [];
            if (array_key_exists('website', $data) && $data['website'] !== $brand->website) {
                unset($settings['website_check']);
                $settings['website_check_revision'] = bin2hex(random_bytes(16));
            }
            if (array_key_exists('aliases', $data)) {
                $settings['aliases'] = array_values(array_unique(array_filter(array_map('trim', $data['aliases'] ?? []), fn ($value) => $value !== '')));
            }
            $attributes = array_intersect_key($data, array_flip(['name', 'website', 'industry', 'market', 'language', 'timezone']));
            $attributes['name'] = trim($attributes['name']);
            $brand->fill($attributes)->forceFill(['settings' => $settings])->save();
            $this->audit($ctx, 'brand.settings.general_updated', 'brand', $brand->id, ['fields' => array_keys($data)]);
        });
    }

    public function theme(Request $request, string $id, array $data, ?string $png): void
    {
        $ctx = $this->access->context($id);
        $path = $png === null ? null : 'organizations/'.$ctx->organizationId.'/onboarding/logos/'.bin2hex(random_bytes(16)).'.png';
        $disk = Storage::disk('local');
        if ($path && ! $disk->put($path, $png, ['visibility' => 'private'])) {
            throw new RuntimeException('Unable to store brand logo.');
        }
        try {
            $this->access->change($request, $id, function (Brand $brand, TenantContext $ctx) use ($data, $path): void {
                $settings = $brand->settings ?? [];
                $old = $settings['logo_path'] ?? null;
                $settings['theme'] = array_merge($settings['theme'] ?? [], ['subtitle' => trim($data['subtitle'] ?? ''), 'promo_card' => (bool) $data['promo_card']]);
                if ($path) {
                    $settings['logo_path'] = $path;
                } elseif ($data['remove_logo'] ?? false) {
                    unset($settings['logo_path']);
                }
                $brand->forceFill(['theme' => $data['theme'], 'settings' => $settings])->save();
                $this->audit($ctx, 'brand.settings.theme_updated', 'brand', $brand->id, ['theme' => $data['theme'], 'logo_changed' => $path !== null || ($data['remove_logo'] ?? false)]);
                if ($old && $old !== ($settings['logo_path'] ?? null) && self::validLogoPath($ctx->organizationId, $old)) {
                    DB::afterCommit(static function () use ($ctx, $old): void {
                        try {
                            if (! DB::table('brands')->where('organization_id', $ctx->organizationId)->where('settings->logo_path', $old)->exists()) {
                                Storage::disk('local')->delete($old);
                            }
                        } catch (Throwable) {
                            // Keep a private orphan if cleanup is unavailable; never break a committed update.
                        }
                    });
                }
            });
        } catch (Throwable $error) {
            if ($path) {
                try {
                    if (! DB::table('brands')->where('organization_id', $ctx->organizationId)->where('settings->logo_path', $path)->exists()) {
                        $disk->delete($path);
                    }
                } catch (Throwable) {
                    // Unknown commit outcome: retain the private file.
                }
            }
            throw $error;
        }
    }

    public function addDomain(Request $request, string $id, array $data): void
    {
        $this->access->change($request, $id, function (Brand $brand, TenantContext $ctx) use ($data): void {
            if (BrandDomain::where('host', $data['host'])->exists()) {
                throw ValidationException::withMessages(['host' => 'Этот домен уже добавлен к бренду.'])->redirectTo(route('brands.settings.show', $brand->id));
            }
            $domain = BrandDomain::create([...$data, 'status' => 'pending', 'verification_token' => bin2hex(random_bytes(24))]);
            $this->audit($ctx, 'brand.domain.added', 'brand_domain', $domain->id, ['host' => $domain->host, 'purpose' => $domain->purpose, 'verification_method' => $domain->verification_method]);
        });
    }

    public function verifyDomain(Request $request, string $id, string $domainId): void
    {
        $brand = $this->access->read($request, $id);
        abort_unless($brand->status === 'active', 404);
        $snapshot = BrandDomain::withoutGlobalScopes()->where('organization_id', $brand->organization_id)->where('brand_id', $brand->id)->whereKey($domainId)->firstOrFail();
        $this->requireExternalChecks($id, 'domain');
        $verified = false;
        $error = 'Подтверждение не найдено. Проверьте настройки и повторите попытку.';
        try {
            if ($snapshot->verification_method === 'dns') {
                foreach ($this->dns->txtRecords($snapshot->instructions()['dns_name']) as $txt) {
                    if (is_string($txt) && hash_equals($snapshot->verification_token, $txt)) {
                        $verified = true;
                        break;
                    }
                }
            } else {
                $response = $this->http->get('https://'.$snapshot->host.$snapshot->instructions()['file_path'], 4096);
                $verified = $response->status() === 200 && hash_equals($snapshot->verification_token, trim($response->body()));
            }
        } catch (Throwable) {
            $error = 'Не удалось безопасно проверить домен. Проверьте публичные DNS-записи и доступность сервера.';
        }
        // Network work is outside SQL locks. Recheck actor, tenant, token and revision before committing.
        $this->access->change($request, $id, function (Brand $brand, TenantContext $ctx) use ($snapshot, $verified, $error): void {
            $domain = BrandDomain::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            abort_unless($domain->check_version === $snapshot->check_version && hash_equals($domain->verification_token, $snapshot->verification_token), 409, 'Данные изменились. Повторите проверку.');
            $domain->fill(['status' => $verified ? 'verified' : 'failed', 'verified_at' => $verified ? now() : null, 'last_checked_at' => now(), 'last_error' => $verified ? null : $error, 'check_version' => $domain->check_version + 1])->save();
            $this->audit($ctx, $verified ? 'brand.domain.verified' : 'brand.domain.verification_failed', 'brand_domain', $domain->id, ['host' => $domain->host, 'status' => $domain->status]);
        });
    }

    public function removeDomain(Request $request, string $id, string $domainId): void
    {
        $this->access->change($request, $id, function (Brand $brand, TenantContext $ctx) use ($domainId): void {
            $domain = BrandDomain::whereKey($domainId)->lockForUpdate()->firstOrFail();
            $domain->delete();
            $this->audit($ctx, 'brand.domain.removed', 'brand_domain', $domain->id, ['host' => $domain->host]);
        });
    }

    public function checkWebsite(Request $request, string $id): void
    {
        $snapshot = $this->access->read($request, $id);
        abort_unless($snapshot->status === 'active', 404);
        if (! $snapshot->website) {
            throw ValidationException::withMessages(['website' => 'Сначала сохраните адрес сайта.'])->redirectTo(route('brands.settings.show', $id));
        }
        $this->requireExternalChecks($id, 'website');
        $revision = $snapshot->settings['website_check_revision'] ?? null;
        $result = ['status' => 'unavailable', 'checked_at' => now()->toIso8601String(), 'robots_blocked' => null, 'error' => null];
        try {
            $response = $this->http->get($snapshot->website);
            if ($response->successful()) {
                $result['status'] = 'available';
                $parts = parse_url($snapshot->website);
                try {
                    $robots = $this->http->get($parts['scheme'].'://'.$parts['host'].'/robots.txt', 65536);
                    if ($robots->successful()) {
                        $result['robots_blocked'] = $this->blocksAllRobots($robots->body());
                    } elseif ($robots->status() === 404) {
                        $result['robots_blocked'] = false;
                    }
                } catch (Throwable) {
                    // Website availability and robots availability are separate facts.
                }
            } else {
                $result['error'] = 'Сайт не вернул успешный ответ. Перенаправления автоматически не выполняются.';
            }
        } catch (Throwable) {
            $result['error'] = 'Не удалось безопасно проверить сайт. Проверьте публичный адрес и доступность сервера.';
        }
        $result['checked_at'] = now()->toIso8601String();
        $this->access->change($request, $id, function (Brand $brand, TenantContext $ctx) use ($snapshot, $revision, $result): void {
            $settings = $brand->settings ?? [];
            abort_unless($brand->website === $snapshot->website && ($settings['website_check_revision'] ?? null) === $revision, 409, 'Данные изменились. Повторите проверку.');
            $settings['website_check_revision'] = bin2hex(random_bytes(16));
            $settings['website_check'] = $result;
            $brand->forceFill(['settings' => $settings])->save();
            $this->audit($ctx, 'brand.website.checked', 'brand', $brand->id, ['status' => $result['status'], 'robots_blocked' => $result['robots_blocked']]);
        });
    }

    private function requireExternalChecks(string $id, string $field): void
    {
        if (config('features.E1-05.defaults.external_checks', false) !== true) {
            throw ValidationException::withMessages([$field => 'Внешние проверки пока недоступны.'])->redirectTo(route('brands.settings.show', $id));
        }
    }

    public static function validLogoPath(string $organization, string $path): bool
    {
        return preg_match('~^organizations/'.preg_quote($organization, '~').'/onboarding/logos/[a-f0-9]{32}\.(?:png|svg)$~D', $path) === 1;
    }

    private function blocksAllRobots(string $body): bool
    {
        $agents = [];
        $hasRules = false;
        $blocked = false;
        $allowed = false;
        foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
            $line = trim(explode('#', $line, 2)[0]);
            if (! str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            $key = strtolower($key);
            if ($key === 'user-agent') {
                if ($hasRules) {
                    $agents = [];
                    $hasRules = false;
                }
                $agents[] = strtolower($value);
            } elseif (in_array($key, ['allow', 'disallow'], true)) {
                $hasRules = true;
                if (in_array('*', $agents, true) && $value === '/') {
                    $blocked = $blocked || $key === 'disallow';
                    $allowed = $allowed || $key === 'allow';
                }
            }
        }

        return $blocked && ! $allowed;
    }

    private function audit(TenantContext $ctx, string $action, string $type, string $id, ?array $after = null): void
    {
        app(AuditWriter::class)->record($ctx, new AuditEntry($action, $type, $id, after: $after));
    }
}
