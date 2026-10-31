<?php

namespace App\Shell\Notifications;

use App\Models\Membership;
use App\Shell\Audit\AuditedAction;
use App\Shell\Audit\AuditEntry;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ConfigureNotification
{
    use AuditedAction;

    public function execute(TenantContext $context, string $eventKey, string $channel, bool $enabled, ?string $destination = null): void
    {
        $organization = $context->requireOrganizationId();
        if (! $context->actorId || ! $context->brandId || ! $context->membershipId) {
            abort(403);
        }
        $member = Membership::withoutGlobalScopes()->whereKey($context->membershipId)->where('organization_id', $organization)->where('user_id', $context->actorId)->where('status', 'active')->first();
        if (! $member || ! $member->permitsBrand($context->brandId)) {
            abort(403);
        }
        if (! isset(EventCatalog::TITLES[$eventKey]) || ! in_array($channel, ['cabinet', 'email', 'telegram'], true)) {
            throw ValidationException::withMessages(['event' => 'Неизвестное событие или канал.']);
        }
        if ($destination !== null && ($channel !== 'telegram' || ! preg_match('/^-?[0-9]{1,20}$/D', $destination))) {
            throw ValidationException::withMessages(['destination' => 'Неверный идентификатор Telegram.']);
        }
        $where = ['organization_id' => $organization, 'brand_id' => $context->brandId, 'user_id' => $context->actorId, 'event_key' => $eventKey, 'channel' => $channel];
        $this->audited($context, function () use ($where, $enabled, $destination): array {
            $old = DB::table('notification_settings')->where($where)->lockForUpdate()->first();
            $destinationValue = $destination !== null ? Crypt::encryptString($destination) : $old?->destination;
            if ($where['channel'] === 'telegram' && $enabled && ! $destinationValue) {
                throw ValidationException::withMessages(['destination' => 'Telegram не подключён.']);
            }
            DB::table('notification_settings')->upsert([[...$where, 'id' => (string) Str::ulid(), 'enabled' => $enabled, 'destination' => $destinationValue, 'created_at' => now(), 'updated_at' => now()]], ['brand_id', 'user_id', 'event_key', 'channel'], ['enabled', 'destination', 'updated_at']);

            return ['before' => $old ? (bool) $old->enabled : null, 'after' => $enabled];
        }, fn (array $result) => new AuditEntry('notification.settings.updated', 'notification_setting', $eventKey.':'.$channel, ['enabled' => $result['before']], ['enabled' => $result['after']]));
    }
}
