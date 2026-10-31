<?php

namespace App\Shell\Auth;

use App\Models\Organization;
use App\Shell\Team\Models\Invitation;
use App\Tenancy\Cabinet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The database, not a potentially stale serialized session, is authoritative. */
final class InvitationExchangeGrants
{
    public function beginDocument(Request $request): string
    {
        $session = $this->sessionHash($request->session()->getId());
        $epoch = bin2hex(random_bytes(32));
        DB::transaction(function () use ($session, $epoch): void {
            DB::table('invitation_exchange_sessions')->insertOrIgnore([
                'session_hash' => $session, 'document_epoch' => $epoch,
                'sequence' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('invitation_exchange_sessions')->where('session_hash', $session)->lockForUpdate()->first();
            DB::table('invitation_exchange_sessions')->where('session_hash', $session)->update([
                'document_epoch' => $epoch, 'sequence' => 0, 'updated_at' => now(),
            ]);
        });

        return $epoch;
    }

    /** The caller serializes authenticated requests against logout before entering here. */
    public function exchange(Request $request, ?string $hash, ?string $epoch, ?int $sequence): bool
    {
        return DB::transaction(function () use ($request, $hash, $epoch, $sequence): bool {
            $key = $this->sessionHash($request->session()->getId());
            $row = DB::table('invitation_exchange_sessions')->where('session_hash', $key)->lockForUpdate()->first();
            if (! $row || ! is_string($epoch) || ! hash_equals($row->document_epoch, $epoch)
                || ! is_int($sequence) || $sequence < 1 || $sequence <= (int) $row->sequence) {
                return false;
            }
            DB::table('invitation_exchange_sessions')->where('session_hash', $key)->update([
                'sequence' => $sequence, 'invitation_id' => null, 'grant_expires_at' => null, 'updated_at' => now(),
            ]);
            if (! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                return false;
            }
            $org = app(Cabinet::class)->organizationId();
            if (! Organization::whereKey($org)->where('status', 'active')->lockForUpdate()->exists()) {
                return false;
            }
            $invite = Invitation::withoutGlobalScopes()->where('organization_id', $org)->where('token_hash', $hash)
                ->whereNull('exchanged_at')->whereNull('accepted_at')->whereNull('revoked_at')
                ->where('expires_at', '>', now())->lockForUpdate()->first();
            if (! $invite || ($request->user() && mb_strtolower(trim($request->user()->email)) !== $invite->email)) {
                return false;
            }
            // This marker is immutable; the same row lock protects the direct acceptance API.
            DB::table('invitations')->where('id', $invite->id)->whereNull('exchanged_at')->update(['exchanged_at' => now(), 'updated_at' => now()]);
            DB::table('invitation_exchange_sessions')->where('session_hash', $key)->update([
                'invitation_id' => $invite->id,
                'grant_expires_at' => min($invite->expires_at, now()->addMinutes(max(1, (int) config('session.lifetime', 120)))),
                'updated_at' => now(),
            ]);

            return true;
        });
    }

    public function invitationId(Request $request, bool $lock = false): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }
        $query = DB::table('invitation_exchange_sessions')->where('session_hash', $this->sessionHash($request->session()->getId()))
            ->whereNotNull('invitation_id')->where('grant_expires_at', '>', now());
        $row = ($lock ? $query->lockForUpdate() : $query)->first();

        return $row?->invitation_id;
    }

    /** Move the grant and retire every old-document epoch after a session ID rotation. */
    public function rotate(string $oldId, string $newId): void
    {
        $old = $this->sessionHash($oldId);
        DB::table('invitation_exchange_sessions')->where('session_hash', $old)->update([
            'session_hash' => $this->sessionHash($newId), 'document_epoch' => bin2hex(random_bytes(32)),
            'sequence' => 0, 'updated_at' => now(),
        ]);
    }

    public function revoke(Request $request): void
    {
        DB::table('invitation_exchange_sessions')->where('session_hash', $this->sessionHash($request->session()->getId()))->delete();
    }

    private function sessionHash(string $id): string
    {
        return hash_hmac('sha256', 'invitation-session:'.$id, (string) config('app.key'));
    }
}
