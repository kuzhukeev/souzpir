<?php

namespace App\Seller\Channels\Email;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EmailDeliverySettings
{
    private function query(TenantContext $tenant): mixed
    {
        return DB::table('channel_accounts')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('type', 'email')->where('name', 'Исходящая почта');
    }

    public function read(TenantContext $tenant): array
    {
        $row = $this->query($tenant)->first();
        $settings = $row ? json_decode($row->settings, true) : [];

        return array_merge(['mode' => 'eyon', 'signature' => '', 'smtp_host' => '', 'smtp_port' => 587, 'smtp_username' => '', 'smtp_security' => 'tls'], is_array($settings) ? $settings : [], ['password_set' => (bool) $row?->credentials]);
    }

    public function password(TenantContext $tenant): ?string
    {
        $value = $this->query($tenant)->value('credentials');

        return $value ? Crypt::decryptString($value) : null;
    }

    public function save(TenantContext $tenant, array $settings, ?string $password): void
    {
        $row = $this->query($tenant)->first();
        $values = ['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'credentials' => $password ? Crypt::encryptString($password) : $row?->credentials, 'updated_at' => now()];
        if ($row) {
            $this->query($tenant)->update($values);
        } else {
            DB::table('channel_accounts')->insert($values + ['id' => (string) Str::ulid(), 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId, 'type' => 'email', 'name' => 'Исходящая почта', 'status' => 'unconfigured', 'created_at' => now()]);
        }
    }
}
