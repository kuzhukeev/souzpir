<?php

namespace App\Shell\Team\Http;

use App\Models\Membership;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class TeamMemberAvatarController
{
    public function __invoke(string $membership, TenantContext $context): Response
    {
        Gate::authorize('team.read');
        $member = Membership::query()->findOrFail($membership);
        Gate::authorize('view', $member);
        abort_unless($context->organizationId === $member->organization_id && $context->brandId && $member->permitsBrand($context->brandId), 404);
        $path = DB::table('profile_preferences')->where('user_id', $member->user_id)->value('avatar_path');
        abort_unless(is_string($path) && preg_match('#^users/'.preg_quote($member->user_id, '#').'/profile/[a-f0-9]{32}\\.png$#D', $path), 404);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response(Storage::disk('local')->get($path), 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'"]);
    }
}
