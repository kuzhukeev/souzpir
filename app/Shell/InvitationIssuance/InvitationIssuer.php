<?php

namespace App\Shell\InvitationIssuance;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\SessionBrandSelection;
use App\Shell\Plans\LimitEnforcer;
use App\Shell\Team\Models\Invitation;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class InvitationIssuer
{
    private const LIFETIME_SECONDS = 7 * 24 * 60 * 60;

    public function issue(#[\SensitiveParameter] Request $request, #[\SensitiveParameter] array $data): string
    {
        Gate::authorize('team.write');
        $context = app(TenantContext::class);
        $origin = $this->origin();
        $encoded = config('features.E1-06B.defaults.attempt_hmac_key');
        abort_unless(is_string($encoded) && str_starts_with($encoded, 'base64:'), 503);
        $key = base64_decode(substr($encoded, 7), true);
        abort_unless(is_string($key) && strlen($key) >= 32 && hash_equals(base64_encode($key), substr($encoded, 7)), 503);
        $attemptHash = hash_hmac('sha256', 'invitation-issuance-v1:'.$data['attempt_key'], $key);
        unset($key, $encoded);

        $generating = false;
        try {
            return app(AuthSessionRegistry::class)->withinActive($request, function (User $user) use ($request, $context, $data, $attemptHash, $origin, &$generating): string {
                $expected = $request->attributes->get('tenant.brand_revision');
                $selection = app(SessionBrandSelection::class)->current($request);
                abort_unless(is_string($expected) && (string) $selection->brand_revision === $expected
                    && $selection->selected_organization_id === $context->organizationId
                    && $selection->selected_brand_id === $context->brandId, 409);
                $provided = $request->header('X-Brand-Revision');
                $actual = app(SessionBrandSelection::class)->profileRevision($request, $expected);
                abort_unless(is_string($provided) && hash_equals($actual, $provided), 409);

                Organization::query()->whereKey($context->organizationId)->where('status', 'active')->lockForUpdate()->firstOrFail();
                $actor = Membership::withoutGlobalScopes()->whereKey($context->membershipId)
                    ->where('organization_id', $context->organizationId)->where('user_id', $user->id)
                    ->where('status', 'active')->lockForUpdate()->first();
                abort_unless($actor && in_array($actor->role, ['owner', 'admin'], true)
                    && Gate::forUser($user)->allows('team.write') && $context->brandId && $actor->permitsBrand($context->brandId), 403);
                $ids = array_values($data['brand_ids']);
                foreach ($ids as $id) {
                    abort_unless($actor->permitsBrand($id), 404);
                }
                $allIds = array_values(array_unique([...$ids, $context->brandId]));
                $active = Brand::withoutGlobalScopes()->where('organization_id', $context->organizationId)
                    ->where('status', 'active')->whereIn('id', $allIds)->orderBy('id')->lockForUpdate()->get(['id'])->count();
                abort_unless($active === count($allIds), 404);
                $activeMembers = Membership::withoutGlobalScopes()->where('organization_id', $context->organizationId)->where('status', 'active')->count();
                app(LimitEnforcer::class)->assertWithin($context, 'users.max', (string) ($activeMembers + 1));
                $attempt = DB::table('invitation_issuance_attempts')->where('organization_id', $context->organizationId)
                    ->where('actor_user_id', $user->id)->where('attempt_hash', $attemptHash)->exists();
                abort_if($attempt, 409);
                // The organization lock serializes same-organization issuance; the unique key is a second fence.
                $generating = true;
                try {
                    $secret = Str::random(64);
                    $issuedAt = now()->utc()->startOfSecond()->toImmutable();
                    $invitation = new Invitation([
                        'email' => $data['email'], 'role' => $data['role'], 'brand_ids' => $ids,
                        'settings' => ['sales_manager' => (bool) $data['sales_manager']],
                        'token_hash' => hash('sha256', $secret), 'invited_by' => $user->id, 'expires_at' => $issuedAt->addSeconds(self::LIFETIME_SECONDS),
                    ]);
                    $invitation->created_at = $issuedAt;
                    $invitation->updated_at = $issuedAt;
                    $invitation->save();
                    abort_unless($invitation->organization_id === $context->organizationId, 500);
                    DB::table('invitation_issuance_attempts')->insert([
                        'organization_id' => $context->organizationId, 'actor_user_id' => $user->id,
                        'attempt_hash' => $attemptHash, 'invitation_id' => $invitation->id, 'created_at' => now(),
                    ]);
                    app(AuditWriter::class)->record($context, new AuditEntry('team.invitation.created', 'invitation', $invitation->id));

                    return $origin.route('invitation.open', absolute: false).'#'.$secret;
                } catch (Throwable) {
                    // Never report an exception created while the bearer exists, including its arguments.
                    unset($secret);
                    throw new HttpException(500, 'Не удалось выпустить приглашение.');
                }
            });
        } catch (Throwable $error) {
            if (! $generating) {
                throw $error;
            }
            // Commit itself can fail after the callback produced a bearer.
            throw new HttpException(500, 'Не удалось выпустить приглашение.');
        }
    }

    private function origin(): string
    {
        $url = config('app.url');
        $parts = is_string($url) ? parse_url($url) : false;
        abort_unless(is_array($parts) && isset($parts['scheme'], $parts['host'])
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment'])
            && in_array($parts['path'] ?? '', ['', '/'], true), 503);
        $scheme = strtolower($parts['scheme']);
        $local = app()->environment('local', 'testing') && in_array(strtolower($parts['host']), ['souzpir.local', 'localhost', '127.0.0.1', '[::1]'], true);
        abort_unless($scheme === 'https' || ($scheme === 'http' && $local), 503);

        return rtrim($url, '/');
    }
}
