<?php

namespace App\Migration;

use InvalidArgumentException;
use SQLite3;

/** Read-only assessment of two operator-prepared SQLite copies. */
final class OrganizationDryRun
{
    public function inspect(string $sourcePath, string $targetPath, string $organizationId, string $ownerId): array
    {
        if (! preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $organizationId) || ! preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $ownerId)) {
            throw new InvalidArgumentException('Идентификаторы организации и владельца должны быть ULID.');
        }
        $source = $this->open($sourcePath);
        $target = $this->open($targetPath);
        try {
            $organization = $this->count($source, 'SELECT count(*) FROM organizations WHERE id = :id', $organizationId);
            $brands = $this->count($source, 'SELECT count(*) FROM brands WHERE organization_id = :id', $organizationId);
            $existing = $this->count($target, 'SELECT count(*) FROM organizations WHERE id = :id', $organizationId);
            $owner = $this->count($target, 'SELECT count(*) FROM users WHERE id = :id', $ownerId);
            $collisions = $this->brandCollisions($source, $target, $organizationId);
            $errors = [];
            if ($organization !== 1) {
                $errors[] = 'Организация отсутствует в копии источника.';
            }
            if ($owner !== 1) {
                $errors[] = 'Владелец не найден в копии целевой базы.';
            }
            if ($existing) {
                $errors[] = 'Организация уже существует в целевой базе; требуется проверка сопоставления.';
            }
            if ($collisions) {
                $errors[] = 'Идентификаторы брендов уже существуют в целевой базе: '.$collisions.'.';
            }

            return ['source' => ['organizations' => $organization, 'brands' => $brands], 'target_before' => ['organizations' => $existing, 'brands_with_same_id' => $collisions], 'target_after' => ['organizations' => $existing, 'brands_with_same_id' => $collisions], 'errors' => $errors];
        } finally {
            $source->close();
            $target->close();
        }
    }

    private function open(string $path): SQLite3
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('Копия SQLite недоступна.');
        }
        $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
        $db->enableExceptions(true);

        return $db;
    }

    private function count(SQLite3 $db, string $sql, string $id): int
    {
        $statement = $db->prepare($sql);
        $statement->bindValue(':id', $id, SQLITE3_TEXT);

        return (int) $statement->execute()->fetchArray(SQLITE3_NUM)[0];
    }

    private function brandCollisions(SQLite3 $source, SQLite3 $target, string $organizationId): int
    {
        $statement = $source->prepare('SELECT id FROM brands WHERE organization_id = :id');
        $statement->bindValue(':id', $organizationId, SQLITE3_TEXT);
        $rows = $statement->execute();
        $count = 0;
        while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
            $count += $this->count($target, 'SELECT count(*) FROM brands WHERE id = :id', $row['id']);
        }

        return $count;
    }
}
