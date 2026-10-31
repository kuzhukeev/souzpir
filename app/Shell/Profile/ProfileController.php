<?php

namespace App\Shell\Profile;

use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\SecureAccountContext;
use App\Tenancy\TenantContext;
use DateTimeZone;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ProfileController
{
    public function show(Request $request): Response
    {
        $profile = app(ProfileAccess::class)->run($request, function (User $user, TenantContext $context, object $brand, object $member, object $org) use ($request): array {
            $preferences = DB::table('profile_preferences')->where('user_id', $user->id)->first();

            return [
                'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone,
                'timezone' => $preferences?->timezone, 'effective_timezone' => $preferences?->timezone ?? $brand->timezone, 'brand_timezone' => $brand->timezone,
                'language' => 'ru', 'avatar_url' => $preferences?->avatar_path ? route('profile.avatar', ['v' => substr(hash('sha256', $preferences->avatar_path), 0, 16)]) : null,
                'role' => $member->role, 'organization_name' => $org->name,
                'pending_email' => app(EmailChanges::class)->pending($user),
                'email_change_available' => SecureAccountContext::allows($request) && app(EmailChanges::class)->available(),
                'email_confirmation_secure' => SecureAccountContext::canonicalEmailAllowed(),
            ];
        });

        return Inertia::render('Shell/Profile/Profile', ['profile' => $profile, 'timezones' => DateTimeZone::listIdentifiers(), 'status' => session('status')]);
    }

    public function update(#[\SensitiveParameter] Request $request): RedirectResponse
    {
        $values = ProfileValidation::validate($request, [
            'name' => ['required', 'string', 'max:120', 'regex:/\S/u'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[+0-9()\s.-]+$/D'],
            'timezone' => ['nullable', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'language' => ['required', Rule::in(['ru'])],
        ]);
        try {
            app(ProfileAccess::class)->run($request, function (User $user, TenantContext $context) use ($values): void {
                $nameChanged = $user->name !== trim($values['name']);
                $phoneChanged = $user->phone !== ($values['phone'] ?? null);
                $user->forceFill(['name' => trim($values['name']), 'phone' => $values['phone'] ?? null])->save();
                DB::table('profile_preferences')->upsert([['user_id' => $user->id, 'timezone' => $values['timezone'] ?? null, 'language' => 'ru', 'created_at' => now(), 'updated_at' => now()]], ['user_id'], ['timezone', 'language', 'updated_at']);
                app(AuditWriter::class)->record($context, new AuditEntry('profile.updated', 'user', $user->id, after: ['name_changed' => $nameChanged, 'phone_changed' => $phoneChanged, 'timezone' => $values['timezone'] ?? null, 'language' => 'ru']));
            });
        } catch (ValidationException|AuthenticationException|HttpExceptionInterface $error) {
            throw $error;
        } catch (Throwable) {
            throw new \RuntimeException('Profile update processing failed.');
        }

        return redirect()->route('profile.show')->with('status', 'Профиль сохранён.');
    }

    public function avatar(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $path = app(ProfileAccess::class)->run($request, function (User $user): string {
            $path = DB::table('profile_preferences')->where('user_id', $user->id)->value('avatar_path');
            abort_unless(is_string($path) && $this->ownsAvatarPath($path, $user->id), 404);

            return $path;
        });
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response(Storage::disk('local')->get($path), 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'"]);
    }

    public function storeAvatar(#[\SensitiveParameter] Request $request): RedirectResponse
    {
        ProfileValidation::validate($request, ['avatar' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:max_width=2048,max_height=2048']]);
        $image = @imagecreatefromstring($request->file('avatar')->getContent());
        if (! $image) {
            throw ValidationException::withMessages(['avatar' => 'Не удалось прочитать изображение.']);
        }
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        if (! is_string($png) || strlen($png) > 4 * 1024 * 1024) {
            throw ValidationException::withMessages(['avatar' => 'Изображение слишком велико.']);
        }
        $path = null;
        try {
            app(ProfileAccess::class)->run($request, function (User $user, TenantContext $context) use ($png, &$path): void {
                $path = 'users/'.$user->id.'/profile/'.bin2hex(random_bytes(16)).'.png';
                $old = DB::table('profile_preferences')->where('user_id', $user->id)->value('avatar_path');
                if (! Storage::disk('local')->put($path, $png)) {
                    throw ValidationException::withMessages(['avatar' => 'Не удалось сохранить изображение.']);
                }
                DB::table('profile_preferences')->upsert([['user_id' => $user->id, 'avatar_path' => $path, 'created_at' => now(), 'updated_at' => now()]], ['user_id'], ['avatar_path', 'updated_at']);
                app(AuditWriter::class)->record($context, new AuditEntry('profile.avatar.updated', 'user', $user->id));
                if ($old) {
                    DB::afterCommit(fn () => $this->cleanupAvatar($old, $user->id));
                }
            });
        } catch (Throwable $error) {
            // A failed COMMIT acknowledgement does not prove a rollback. Keep the
            // private file for later reconciliation; it may already be referenced.
            if ($error instanceof ValidationException || $error instanceof AuthenticationException || $error instanceof HttpExceptionInterface) {
                throw $error;
            }
            throw new \RuntimeException('Avatar update processing failed.');
        }

        return redirect()->route('profile.show')->with('status', 'Аватар сохранён.');
    }

    public function deleteAvatar(Request $request): RedirectResponse
    {
        app(ProfileAccess::class)->run($request, function (User $user, TenantContext $context): void {
            $old = DB::table('profile_preferences')->where('user_id', $user->id)->value('avatar_path');
            DB::table('profile_preferences')->where('user_id', $user->id)->update(['avatar_path' => null, 'updated_at' => now()]);
            app(AuditWriter::class)->record($context, new AuditEntry('profile.avatar.removed', 'user', $user->id));
            if ($old) {
                DB::afterCommit(fn () => $this->cleanupAvatar($old, $user->id));
            }
        });

        return redirect()->route('profile.show')->with('status', 'Аватар удалён.');
    }

    private function ownsAvatarPath(string $path, string $userId): bool
    {
        return preg_match('#^users/'.preg_quote($userId, '#').'/profile/[a-f0-9]{32}\.png$#D', $path) === 1;
    }

    private function cleanupAvatar(string $path, string $userId): void
    {
        if (! $this->ownsAvatarPath($path, $userId)) {
            return;
        }
        try {
            Storage::disk('local')->delete($path);
        } catch (Throwable) {
            // The replacement has already committed. Keep its file and retry cleanup operationally.
            Log::warning('Profile avatar cleanup failed.', ['user_id' => $userId]);
        }
    }
}
