<?php

namespace App\Shell\Data;

use App\Models\Membership;
use App\Providers\Features\FeatureRegistry;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Data\Contracts\ExportContributor;
use App\Shell\Data\Contracts\ExportQuery;
use App\Shell\Data\Contracts\ExportRecord;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use LogicException;
use ZipArchive;

final class DataController
{
    /** @return array<string, ExportContributor> */
    private function contributors(): array
    {
        $items = [];
        foreach (app(FeatureRegistry::class)->extensions() as $key => $class) {
            if (! in_array($key, ['export.seller', 'export.vision'], true)) continue;
            $contributor = app($class);
            if (! $contributor instanceof ExportContributor || $key !== 'export.'.$contributor->module() || isset($items[$contributor->module()])) {
                throw new LogicException('Invalid export contributor registration.');
            }
            $items[$contributor->module()] = $contributor;
        }
        return $items;
    }

    public function index(Request $request)
    {
        $ctx = app(TenantContext::class);
        $canExport = $this->active($request, $ctx, fn () => $this->isOwner($ctx), false);
        $lastConsent = DB::table('audit_logs')->where('organization_id', $ctx->organizationId)->where('subject_type', 'user')->where('subject_id', $ctx->actorId)->where('action', 'data.marketing_consent.changed')->orderByDesc('created_at')->orderByDesc('id')->first();
        $consentAfter = $lastConsent ? json_decode($lastConsent->after ?? 'null', true) : null;
        $consentDate = is_array($consentAfter) && array_key_exists('marketing_opt_in', $consentAfter) && $consentAfter['marketing_opt_in'] === (bool) $request->user()->marketing_opt_in ? $lastConsent->created_at : null;
        $rows = DB::table('data_exports')->where('organization_id', $ctx->organizationId)
            ->where('brand_id', $ctx->brandId)->where('user_id', $ctx->actorId)->orderByDesc('created_at')->limit(100)->get();
        if (! $canExport) $rows = collect();
        return Inertia::render('Shell/Data/Index', [
            'consents' => [
                'terms_accepted_at' => $request->user()->terms_accepted_at?->toIso8601String(),
                'ai_consent_at' => $request->user()->ai_consent_at?->toIso8601String(),
                'marketing_opt_in' => (bool) $request->user()->marketing_opt_in,
                'marketing_changed_at' => $consentDate,
            ],
            'permissions' => ['can_export' => $canExport],
            'modules' => collect(array_keys($this->contributors()))->map(fn ($module) => [
                'value' => $module, 'label' => $module === 'seller' ? 'Продавец' : 'Видение',
            ])->values(),
            'exports' => $rows->map(fn ($row) => [
                'id' => $row->id, 'module' => $row->module, 'from' => $row->period_from, 'to' => $row->period_to,
                'created_at' => $row->created_at, 'expires_at' => $row->expires_at, 'sha256' => $row->sha256,
                'download_url' => CarbonImmutable::parse($row->expires_at)->isFuture() ? route('data.exports.download', $row->id) : null,
            ]),
        ]);
    }

    public function consents(Request $request)
    {
        $data = $request->validate(['marketing_opt_in' => ['required', 'boolean']]);
        $ctx = app(TenantContext::class);
        app(AuthSessionRegistry::class)->withinActive($request, function ($user) use ($ctx, $data): void {
            $this->lockAccess($ctx, false);
            $before = (bool) $user->marketing_opt_in;
            $after = (bool) $data['marketing_opt_in'];
            if ($before !== $after) {
                $user->forceFill(['marketing_opt_in' => $after])->save();
                app(AuditWriter::class)->record($ctx, new AuditEntry('data.marketing_consent.changed', 'user', $user->id, ['marketing_opt_in' => $before], ['marketing_opt_in' => $after]));
            }
        });
        return redirect()->route('data.index');
    }

    public function create(Request $request)
    {
        $contributors = $this->contributors();
        $ctx = app(TenantContext::class);
        $this->active($request, $ctx, static fn () => null);
        $data = $request->validate([
            'module' => ['required', Rule::in(array_keys($contributors))],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $data['from'], 'Europe/Moscow')->utc();
        $until = CarbonImmutable::createFromFormat('!Y-m-d', $data['to'], 'Europe/Moscow')->addDay()->utc();
        if ($from->diffInDays($until) > 365) throw ValidationException::withMessages(['to' => 'Период не может превышать 365 дней.']);
        $disk = $this->privateDisk();
        $id = (string) Str::ulid();
        $dir = 'data-exports/'.$ctx->requireOrganizationId();
        $disk->makeDirectory($dir);
        $jsonPath = $disk->path($dir.'/'.$id.'.jsonl.tmp');
        $zipPath = $disk->path($dir.'/'.$id.'.zip.tmp');
        $final = $dir.'/'.$id.'.zip';
        $file = fopen($jsonPath, 'xb');
        if ($file === false) abort(503);
        try {
            $this->active($request, $ctx, static fn () => null);
            foreach ($contributors[$data['module']]->stream($ctx, new ExportQuery($from, $until)) as $record) {
                if (! $record instanceof ExportRecord) throw new LogicException('Invalid export record.');
                $line = json_encode(['type' => $record->type, 'data' => $record->data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
                $this->active($request, $ctx, static function () use ($file, $line): void {
                    if (fwrite($file, $line) !== strlen($line)) throw new \RuntimeException('Export write failed.');
                });
            }
            if (! fclose($file)) throw new \RuntimeException('Export write failed.');
            $file = null;
            if (! class_exists(ZipArchive::class)) abort(503, 'Архивация временно недоступна.');
            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true || ! $zip->addFile($jsonPath, 'records.jsonl') || ! $zip->close()) {
                throw new \RuntimeException('Export archive failed.');
            }
            $checksum = hash_file('sha256', $zipPath);
            if (! is_string($checksum)) throw new \RuntimeException('Export checksum failed.');
            $this->active($request, $ctx, function () use ($ctx, $data, $disk, $zipPath, $final, $id, $checksum): void {
                if (! rename($zipPath, $disk->path($final))) throw new \RuntimeException('Export storage failed.');
                DB::table('data_exports')->insert([
                    'id' => $id, 'organization_id' => $ctx->organizationId, 'brand_id' => $ctx->brandId,
                    'user_id' => $ctx->actorId, 'module' => $data['module'], 'period_from' => $data['from'],
                    'period_to' => $data['to'], 'path' => $final, 'sha256' => $checksum,
                    'expires_at' => now()->addMinutes(max(1, (int) config('features.E1-11.defaults.export_ttl_minutes', 1440))), 'created_at' => now(), 'updated_at' => now(),
                ]);
                app(AuditWriter::class)->record($ctx, new AuditEntry('data.export.created', 'data_export', $id, after: ['module' => $data['module']]));
            });
        } catch (\Throwable $error) {
            if (is_resource($file)) fclose($file);
            @unlink($jsonPath);
            @unlink($zipPath);
            $disk->delete($final);
            throw $error;
        }
        @unlink($jsonPath);
        return redirect()->route('data.index');
    }

    public function download(Request $request, string $id)
    {
        abort_unless(Str::isUlid($id), 404);
        $ctx = app(TenantContext::class);
        $disk = $this->privateDisk();
        $row = $this->active($request, $ctx, function () use ($ctx, $id, $disk) {
            $row = $this->exportRow($ctx, $id);
            $expected = 'data-exports/'.$ctx->organizationId.'/'.$id.'.zip';
            abort_unless($row->path === $expected && $disk->exists($expected), 404);
            return $row;
        });
        // Hashing can be slow; do not prevent logout while reading the archive.
        $actual = hash_file('sha256', $disk->path($row->path));
        $row = $this->active($request, $ctx, function () use ($ctx, $id, $row, $actual) {
            $fresh = $this->exportRow($ctx, $id);
            abort_unless($fresh->path === $row->path && is_string($actual)
                && hash_equals($fresh->sha256, $actual), 409);
            app(AuditWriter::class)->record($ctx, new AuditEntry('data.export.downloaded', 'data_export', $id));
            return $fresh;
        });

        return response()->streamDownload(function () use ($request, $ctx, $id, $row, $disk): void {
            $file = fopen($disk->path($row->path), 'rb');
            if ($file === false) return;
            try {
                while (! feof($file) && ! connection_aborted()) {
                    try {
                        $hasData = $this->active($request, $ctx, function () use ($ctx, $id, $file): bool {
                            $this->exportRow($ctx, $id);
                            $chunk = fread($file, 65536);
                            if ($chunk === false || $chunk === '') return false;
                            echo $chunk;
                            if (ob_get_level() > 0) ob_flush();
                            flush();
                            return true;
                        });
                        if (! $hasData) break;
                    } catch (AuthenticationException | \Symfony\Component\HttpKernel\Exception\HttpException $error) {
                        break;
                    }
                }
            } finally {
                fclose($file);
            }
        }, 'data-export-'.$id.'.zip', ['Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function privateDisk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        abort_unless(config('filesystems.disks.local.driver') === 'local', 503);
        $disk = Storage::disk('local');
        abort_unless($disk->makeDirectory('data-exports'), 503);
        $root = realpath($disk->path('data-exports'));
        $public = realpath(public_path());
        abort_unless(is_string($root), 503);
        if (is_string($public)) {
            $root = strtolower(str_replace('\\', '/', $root));
            $public = rtrim(strtolower(str_replace('\\', '/', $public)), '/');
            abort_if($root === $public || str_starts_with($root, $public.'/'), 503);
        }
        return $disk;
    }

    private function exportRow(TenantContext $ctx, string $id): object
    {
        return DB::table('data_exports')->where('id', $id)->where('organization_id', $ctx->organizationId)
            ->where('brand_id', $ctx->brandId)->where('user_id', $ctx->actorId)->where('expires_at', '>', now())
            ->first() ?? abort(404);
    }

    private function active(Request $request, TenantContext $ctx, \Closure $action, bool $ownerOnly = true): mixed
    {
        return app(AuthSessionRegistry::class)->withinActive($request, function () use ($ctx, $action, $ownerOnly) {
            $this->lockAccess($ctx, $ownerOnly);
            return $action();
        });
    }

    private function isOwner(TenantContext $ctx): bool
    {
        return DB::table('memberships')->where('id', $ctx->membershipId)->where('role', 'owner')->exists();
    }

    private function lockAccess(TenantContext $ctx, bool $ownerOnly = true): void
    {
        DB::table('organizations')->where('id', $ctx->organizationId)->where('status', 'active')->lockForUpdate()->first() ?? abort(403);
        DB::table('brands')->where('id', $ctx->brandId)->where('organization_id', $ctx->organizationId)->where('status', 'active')->lockForUpdate()->first() ?? abort(403);
        $member = Membership::withoutGlobalScopes()->whereKey($ctx->membershipId)->where('organization_id', $ctx->organizationId)
            ->where('user_id', $ctx->actorId)->where('status', 'active')->lockForUpdate()->first();
        abort_unless($member && $ctx->brandId && $member->permitsBrand($ctx->brandId) && (! $ownerOnly || $member->role === 'owner'), 403);
    }
}
