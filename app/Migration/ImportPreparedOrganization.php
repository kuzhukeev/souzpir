<?php

namespace App\Migration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class ImportPreparedOrganization
{
    public function import(string $path, string $organizationId, string $ownerId): array
    {
        $this->assertTestDatabase();
        if (! preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/iD', $ownerId)) {
            throw new InvalidArgumentException('Идентификатор владельца должен быть ULID.');
        }
        $data = (new PreparedOrganization)->read($path, $organizationId);
        $this->validateData($data, $ownerId);
        $hash = hash('sha256', json_encode([$data, $ownerId], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($data, $organizationId, $ownerId, $hash): array {
            $batch = DB::table('migration_organization_batches')->where('organization_id', $organizationId)->first();
            if ($batch) {
                if (! hash_equals($batch->source_hash, $hash) || $batch->owner_user_id !== $ownerId) {
                    throw new LogicException('Данные копии отличаются от сохранённого batch.');
                }

                return ['batch' => $batch->id, 'created' => false, 'brands' => $batch->brand_count];
            }
            if (! DB::table('users')->where('id', $ownerId)->exists()) {
                throw new LogicException('Владелец отсутствует в тестовой базе.');
            }
            if (DB::table('organizations')->exists()) {
                throw new LogicException('В тестовой базе уже есть организация.');
            }
            $ids = array_column($data['brands'], 'id');
            if (count($ids) !== count(array_unique($ids)) || DB::table('brands')->whereIn('id', $ids)->exists()) {
                throw new LogicException('Идентификаторы брендов повторяются или уже заняты.');
            }
            $now = now();
            DB::table('organizations')->insert(array_merge($this->fields($data['organization'], ['name', 'status', 'settings']), ['id' => $organizationId, 'owner_user_id' => $ownerId, 'created_at' => $now, 'updated_at' => $now]));
            foreach ($data['brands'] as $brand) {
                DB::table('brands')->insert(array_merge($this->fields($brand, ['name', 'website', 'industry', 'market', 'language', 'timezone', 'theme', 'status', 'settings']), ['id' => $brand['id'], 'organization_id' => $organizationId, 'created_at' => $now, 'updated_at' => $now]));
            }
            if (isset($data['membership'])) {
                DB::table('memberships')->insert(array_merge($this->fields($data['membership'], ['role', 'status', 'brand_ids', 'settings']), ['id' => $data['membership']['id'], 'organization_id' => $organizationId, 'user_id' => $ownerId, 'created_at' => $now, 'updated_at' => $now]));
            }
            $id = (string) Str::ulid();
            DB::table('migration_organization_batches')->insert(['id' => $id, 'organization_id' => $organizationId, 'owner_user_id' => $ownerId, 'source_hash' => $hash, 'brand_count' => count($ids), 'snapshot' => json_encode(['organization' => DB::table('organizations')->where('id', $organizationId)->first(), 'brands' => DB::table('brands')->where('organization_id', $organizationId)->orderBy('id')->get(), 'membership' => DB::table('memberships')->where('organization_id', $organizationId)->where('user_id', $ownerId)->first()], JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]);

            return ['batch' => $id, 'created' => true, 'brands' => count($ids)];
        });
    }

    private function fields(array $row, array $allowed): array
    {
        return array_intersect_key($row, array_flip($allowed));
    }

    private function validateData(array $data, string $ownerId): void
    {
        foreach (array_merge([$data['organization']], $data['brands']) as $row) {
            foreach (['settings'] as $key) {
                if (isset($row[$key]) && (! is_array(json_decode($row[$key], true)) || ! str_starts_with(trim($row[$key]), '{'))) {
                    throw new InvalidArgumentException('Некорректные настройки в подготовленной копии.');
                }
            }
        }
        $membership = $data['membership'] ?? null;
        if ($membership === null) {
            return;
        }
        if (! isset($membership['id'], $membership['user_id']) || ! preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $membership['id']) || $membership['user_id'] !== $ownerId || ($membership['role'] ?? null) !== 'owner') {
            throw new InvalidArgumentException('Членство владельца не совпадает с выбранным пользователем.');
        }
        $brandIds = json_decode($membership['brand_ids'] ?? '[]', true);
        if (! is_array($brandIds) || array_diff($brandIds, array_column($data['brands'], 'id'))) {
            throw new InvalidArgumentException('Членство содержит чужие бренды.');
        }
    }

    public function assertTestDatabase(): void
    {
        $connection = DB::connection();
        $database = $connection->getDatabaseName();
        $host = $connection->getConfig('host');
        if (! app()->environment('testing') || $connection->getDriverName() !== 'pgsql'
            || ! is_string($database) || ! preg_match('/^souzpir_test_[a-z0-9_]+$/D', $database)
            || $database !== config('runtime.test_database') || $database === config('runtime.preview_database')
            || ! in_array($host, ['127.0.0.1', '::1'], true)) {
            throw new LogicException('Импорт разрешён только в отдельную локальную тестовую PostgreSQL.');
        }
        $actual = $connection->selectOne('select current_database() as name');
        if ($actual->name !== $database) {
            throw new LogicException('Подключена другая база.');
        }
    }
}
