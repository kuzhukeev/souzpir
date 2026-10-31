<?php

namespace App\Shell\Profile\TestNotifications;

use App\Models\Membership;
use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

final class TestNotifications
{
    public function availability(): array
    {
        return array_combine(['cabinet', 'email', 'telegram'], array_map(fn (string $channel): bool => app(TestNotificationTransport::class)->available($channel), ['cabinet', 'email', 'telegram']));
    }

    public function recent(TenantContext $context): array
    {
        return DB::table('profile_test_notifications')->where(['organization_id' => $context->organizationId, 'brand_id' => $context->brandId, 'user_id' => $context->actorId])
            ->latest('created_at')->latest('id')->limit(5)->get(['id', 'channel', 'status', 'created_at'])
            ->map(fn (object $row): array => ['id' => $row->id, 'channel' => $row->channel, 'status' => $row->status, 'created_at' => Carbon::parse($row->created_at)->toIso8601String()])->all();
    }

    public function request(#[\SensitiveParameter] Request $request, string $channel, string $key): string
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $context) use ($channel, $key): string {
            $where = ['organization_id' => $context->organizationId, 'brand_id' => $context->brandId, 'user_id' => $user->id, 'request_key' => $key];
            $old = DB::table('profile_test_notifications')->where($where)->first();
            if ($old) {
                abort_unless($old->channel === $channel, 409);

                return $old->id;
            }
            if (! app(TestNotificationTransport::class)->available($channel)) {
                throw ValidationException::withMessages(['channel' => 'Этот канал пока не подключён.']);
            }
            if ($channel === 'telegram' && ! $this->destination($user, $context->organizationId, $context->brandId, $channel)) {
                throw ValidationException::withMessages(['channel' => 'Сначала подключите Telegram.']);
            }
            $id = (string) Str::ulid();
            DB::table('profile_test_notifications')->insert([...$where, 'id' => $id, 'auth_version' => $user->auth_version, 'channel' => $channel, 'status' => $channel === 'cabinet' ? 'sent' : 'pending', 'sent_at' => $channel === 'cabinet' ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
            app(AuditWriter::class)->record($context, new AuditEntry('profile.notification_test.requested', 'profile_notification_test', $id, after: ['channel' => $channel]));

            return $id;
        });
    }

    public function deliver(string $id): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('External test delivery must run outside a transaction.');
        }
        $snapshot = DB::table('profile_test_notifications')->where('id', $id)->first();
        if (! $snapshot || $snapshot->status !== 'pending') {
            return;
        }
        $claimed = DB::transaction(function () use ($snapshot): ?array {
            $user = User::whereKey($snapshot->user_id)->lock('for no key update')->first();
            $org = DB::table('organizations')->where('id', $snapshot->organization_id)->where('status', 'active')->lockForUpdate()->first();
            $brand = DB::table('brands')->where('id', $snapshot->brand_id)->where('organization_id', $snapshot->organization_id)->where('status', 'active')->lockForUpdate()->first();
            $member = Membership::withoutGlobalScopes()->where('organization_id', $snapshot->organization_id)->where('user_id', $snapshot->user_id)->where('status', 'active')->lockForUpdate()->first();
            $row = DB::table('profile_test_notifications')->where('id', $snapshot->id)->lockForUpdate()->first();
            if (! $row || $row->status !== 'pending') {
                return null;
            }
            if (! $user || ! $org || ! $brand || ! $member || ! $member->permitsBrand($brand->id) || (int) $row->auth_version !== $user->auth_version || ! app(TestNotificationTransport::class)->available($row->channel)) {
                DB::table('profile_test_notifications')->where('id', $row->id)->update(['status' => 'cancelled', 'updated_at' => now()]);

                return null;
            }
            $destination = $this->destination($user, $org->id, $brand->id, $row->channel);
            if (! $destination) {
                DB::table('profile_test_notifications')->where('id', $row->id)->update(['status' => 'cancelled', 'updated_at' => now()]);

                return null;
            }
            DB::table('profile_test_notifications')->where('id', $row->id)->update(['status' => 'sending', 'updated_at' => now()]);

            return ['channel' => $row->channel, 'destination' => $destination];
        });
        if (! $claimed) {
            return;
        }
        try {
            $sent = app(TestNotificationTransport::class)->send($claimed['channel'], $claimed['destination']);
        } catch (Throwable) {
            $sent = false;
        }
        DB::table('profile_test_notifications')->where('id', $id)->where('status', 'sending')->update(['status' => $sent ? 'sent' : 'uncertain', 'sent_at' => $sent ? now() : null, 'updated_at' => now()]);
    }

    private function destination(User $user, string $organization, string $brand, string $channel): ?string
    {
        if ($channel === 'email') {
            return $user->hasVerifiedEmail() ? $user->email : null;
        }
        if ($channel !== 'telegram') {
            return null;
        }
        $encrypted = DB::table('profile_telegram_links')->where(['organization_id' => $organization, 'brand_id' => $brand, 'user_id' => $user->id])->value('chat_id');
        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }
        try {
            $chat = Crypt::decryptString($encrypted);

            return preg_match('/\A[1-9][0-9]{0,19}\z/D', $chat) ? $chat : null;
        } catch (Throwable) {
            return null;
        }
    }
}
