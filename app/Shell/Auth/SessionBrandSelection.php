<?php

namespace App\Shell\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Brand selection is protected by the active session lease and its row lock. */
final class SessionBrandSelection
{
    public function current(Request $request): object
    {
        if (DB::connection()->transactionLevel() < 1 || ! $request->user()) {
            throw new LogicException('Brand selection requires an active session transaction.');
        }

        return app(AuthSessionRegistry::class)->withinActive($request, fn (): object => DB::table('auth_session_registry')->where('session_hash', $this->hash($request))
            ->where('user_id', $request->user()->getAuthIdentifier())->lockForUpdate()->first() ?? abort(401));
    }

    /** Initialize only an uninitialized lease; stale Laravel session snapshots never replace it. */
    public function initialize(Request $request, string $organization, string $brand): object
    {
        $row = $this->current($request);
        if ($row->selected_brand_id === null) {
            DB::table('auth_session_registry')->where('session_hash', $this->hash($request))
                ->whereNull('selected_brand_id')->update(['selected_organization_id' => $organization, 'selected_brand_id' => $brand]);
            $row = $this->current($request);
        }

        return $row;
    }

    public function select(Request $request, string $organization, string $brand, string $expected): string
    {
        $row = $this->current($request);
        abort_unless((string) $row->brand_revision === $expected && $row->selected_organization_id === $organization, 409);
        abort_if((string) $row->brand_revision === '9223372036854775807', 409);
        DB::table('auth_session_registry')->where('session_hash', $this->hash($request))->update([
            'selected_brand_id' => $brand,
            'brand_revision' => DB::raw('brand_revision + 1'),
            'updated_at' => now(),
        ]);

        return (string) ((int) $row->brand_revision + 1);
    }

    /** Opaque client revision scoped to this session and internal selection revision. */
    public function profileRevision(Request $request, string $revision): string
    {
        return hash_hmac('sha256', 'profile-context-revision:'.$request->session()->getId().':'.$revision, (string) config('app.key'));
    }

    private function hash(Request $request): string
    {
        return hash_hmac('sha256', 'auth-session:'.$request->session()->getId(), (string) config('app.key'));
    }
}
