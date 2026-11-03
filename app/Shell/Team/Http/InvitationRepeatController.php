<?php

namespace App\Shell\Team\Http;

use App\Shell\Integrations\SecretTransport;
use App\Shell\InvitationIssuance\InvitationIssuer;
use App\Shell\Team\Models\Invitation;
use App\Shell\Team\TeamMemberProjection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class InvitationRepeatController
{
    public function __invoke(Request $request, string $invitation, InvitationIssuer $issuer): JsonResponse
    {
        SecretTransport::assertAllowed($request);
        Gate::authorize('team.write');
        abort_unless($request->isJson() && $request->expectsJson(), 415);
        $data = $request->validate(['attempt_key' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']]);
        $item = Invitation::query()->whereKey($invitation)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->firstOrFail();
        abort_unless(TeamMemberProjection::forCurrentActor()->canRevokeInvitation($item->brand_ids, true), 404);
        abort_if($item->brand_ids === [], 422, 'Создайте новое приглашение с явными брендами.');
        $link = $issuer->issue($request, ['attempt_key' => $data['attempt_key'], 'email' => $item->email, 'role' => $item->role,
            'brand_ids' => $item->brand_ids, 'sales_manager' => (bool) ($item->settings['sales_manager'] ?? false)]);

        return response()->json(['link' => $link], 201, ['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer']);
    }
}
