<?php

namespace App\Migration;

use InvalidArgumentException;
use SQLite3;
use Throwable;

/** Validate the minimum fields needed to import one organization and its brands. */
final class PreparedOrganization
{
    public function read(string $path, string $organizationId): array
    {
        if (! preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $organizationId) || ! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('Нужны ULID организации и доступная подготовленная копия SQLite.');
        }
        try {
            $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
            $db->enableExceptions(true);
            try {
                $organizationColumns = $this->columns($db, 'organizations');
                $brandColumns = $this->columns($db, 'brands');
                $statement = $db->prepare('SELECT '.$this->projection($organizationColumns, ['id', 'name', 'status', 'settings']).' FROM organizations WHERE id = :id');
                $statement->bindValue(':id', $organizationId, SQLITE3_TEXT);
                $organization = $statement->execute()->fetchArray(SQLITE3_ASSOC);
                if (! $organization) {
                    throw new InvalidArgumentException('Организация отсутствует в копии.');
                }
                $this->validateRow($organization, 'организации');
                $statement = $db->prepare('SELECT '.$this->projection($brandColumns, ['id', 'name', 'website', 'industry', 'market', 'language', 'timezone', 'theme', 'status', 'settings']).' FROM brands WHERE organization_id = :id ORDER BY id');
                $statement->bindValue(':id', $organizationId, SQLITE3_TEXT);
                $result = $statement->execute();
                $brands = [];
                while ($brand = $result->fetchArray(SQLITE3_ASSOC)) {
                    $this->validateRow($brand, 'бренда');
                    $brands[] = $brand;
                }

                $membership = null;
                if (in_array('memberships', $this->tables($db), true)) {
                    $columns = $this->columns($db, 'memberships');
                    if (! in_array('organization_id', $columns, true) || ! in_array('user_id', $columns, true)) {
                        throw new InvalidArgumentException('В копии отсутствуют поля членства владельца.');
                    }
                    $statement = $db->prepare('SELECT '.$this->projection($columns, ['id', 'user_id', 'role', 'status', 'brand_ids', 'settings']).' FROM memberships WHERE organization_id = :id AND role = :role');
                    $statement->bindValue(':id', $organizationId, SQLITE3_TEXT);
                    $statement->bindValue(':role', 'owner', SQLITE3_TEXT);
                    $result = $statement->execute();
                    $membership = $result->fetchArray(SQLITE3_ASSOC) ?: null;
                    if ($result->fetchArray(SQLITE3_ASSOC)) {
                        throw new InvalidArgumentException('В копии несколько владельцев организации.');
                    }
                }

                $data = ['organization' => $organization, 'brands' => $brands];
                if ($membership !== null) {
                    $data['membership'] = $membership;
                }

                return $data;
            } finally {
                $db->close();
            }
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable) {
            throw new InvalidArgumentException('Не удалось прочитать поля организации и брендов из копии.');
        }
    }

    private function validateRow(array $row, string $kind): void
    {
        if (! is_string($row['id']) || ! preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $row['id'])
            || ! is_string($row['name']) || trim($row['name']) === '' || mb_strlen($row['name']) > 255) {
            throw new InvalidArgumentException('Некорректный идентификатор или название '.$kind.'.');
        }
    }

    private function columns(SQLite3 $db, string $table): array
    {
        $result = $db->query('PRAGMA table_info('.$table.')');
        $columns = [];
        while ($column = $result->fetchArray(SQLITE3_ASSOC)) {
            $columns[] = $column['name'];
        }

        return $columns;
    }

    private function tables(SQLite3 $db): array
    {
        $result = $db->query("SELECT name FROM sqlite_master WHERE type = 'table'");
        $tables = [];
        while ($table = $result->fetchArray(SQLITE3_ASSOC)) {
            $tables[] = $table['name'];
        }

        return $tables;
    }

    private function projection(array $available, array $allowed): string
    {
        return implode(', ', array_intersect($allowed, $available));
    }
}
