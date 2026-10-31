<?php

namespace App\Shell\Onboarding\Http;

use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Onboarding\OwnerLock;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class LogoController
{
    public function store(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), ['logo' => ['required', 'file', 'max:1024']]);
        if ($validator->fails()) {
            throw (new ValidationException($validator))->redirectTo(route('onboarding.show'));
        }
        $file = $request->file('logo');
        $bytes = file_get_contents($file->getRealPath());
        $info = $bytes === false ? false : @getimagesizefromstring($bytes);
        if (! $info || ! in_array($info['mime'] ?? null, ['image/png', 'image/jpeg', 'image/webp'], true) || $info[0] < 1 || $info[1] < 1 || $info[0] > 2048 || $info[1] > 2048 || $info[0] * $info[1] > 4194304) {
            throw ValidationException::withMessages(['logo' => 'Выберите корректное изображение PNG, JPEG или WebP до 1 МБ и 2048 × 2048 пикселей.'])->redirectTo(route('onboarding.show'));
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw ValidationException::withMessages(['logo' => 'Не удалось прочитать изображение.'])->redirectTo(route('onboarding.show'));
        }
        imagesavealpha($image, true);
        ob_start();
        $success = imagepng($image, null, 6);
        $png = ob_get_clean();
        imagedestroy($image);
        if (! $success || ! is_string($png) || strlen($png) > 4 * 1024 * 1024) {
            throw ValidationException::withMessages(['logo' => 'Не удалось подготовить изображение.'])->redirectTo(route('onboarding.show'));
        }
        $context = app(TenantContext::class);
        $path = 'organizations/'.$context->organizationId.'/onboarding/logos/'.bin2hex(random_bytes(16)).'.png';
        $disk = Storage::disk('local');
        if (! $disk->put($path, $png, ['visibility' => 'private'])) {
            throw new RuntimeException('Unable to store onboarding logo.');
        }
        try {
            app(AuthSessionRegistry::class)->withinActive($request, function () use ($context, $path, $disk): void {
                $org = OwnerLock::acquire($context);
                $progress = DB::table('onboarding_progress')->where('organization_id', $context->organizationId)->lockForUpdate()->first();
                if ($progress?->completed_at !== null || DB::table('brands')->where('organization_id', $context->organizationId)->exists()) {
                    throw ValidationException::withMessages(['logo' => 'Первичная настройка уже завершена или у компании есть бренд.'])->redirectTo(route('onboarding.show'));
                }
                $old = $progress?->logo_path;
                if ($progress) {
                    DB::table('onboarding_progress')->where('organization_id', $context->organizationId)->update(['logo_path' => $path, 'updated_at' => now()]);
                } else {
                    DB::table('onboarding_progress')->insert(['organization_id' => $context->organizationId, 'logo_path' => $path, 'draft' => json_encode(['organization_name' => $org->name], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'step' => 1, 'created_at' => now(), 'updated_at' => now()]);
                }
                app(AuditWriter::class)->record($context, new AuditEntry('onboarding.logo.updated', 'organization', $context->organizationId));
                if ($old && $this->validPath($context->organizationId, $old)) {
                    DB::afterCommit(static function () use ($disk, $old): void {
                        try {
                            $disk->delete($old);
                        } catch (Throwable) { /* Keep the previous file if cleanup is unavailable. */
                        }
                    });
                }
            });
        } catch (Throwable $exception) {
            // A commit may have succeeded even if a later callback failed. Delete
            // only when a fresh database read proves the file is unreferenced.
            try {
                if (! DB::table('onboarding_progress')->where('organization_id', $context->organizationId)->where('logo_path', $path)->exists()) {
                    $disk->delete($path);
                }
            } catch (Throwable) {
                // Unknown outcome: keep the private file for later cleanup.
            }
            throw $exception;
        }

        return redirect()->route('onboarding.show');
    }

    public function show(): Response
    {
        $context = app(TenantContext::class);
        $path = DB::table('onboarding_progress')->where('organization_id', $context->organizationId)->value('logo_path');
        abort_unless(is_string($path) && $this->validPath($context->organizationId, $path), 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404);

        return response($disk->get($path), 200, ['Content-Type' => 'image/png', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    private function validPath(string $org, string $path): bool
    {
        return preg_match('~^organizations/'.preg_quote($org, '~').'/onboarding/logos/[a-f0-9]{32}\\.png$~D', $path) === 1;
    }
}
